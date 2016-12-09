<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Checker\ScopeChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\TypeChecker;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IfScope;
use Psalm\Type;

class IfChecker
{
    /**
     * System of type substitution and deletion
     *
     * for example
     *
     * x: A|null
     *
     * if (x)
     *   (x: A)
     *   x = B  -- effects: remove A from the type of x, add B
     * else
     *   (x: null)
     *   x = C  -- effects: remove null from the type of x, add C
     *
     *
     * x: A|null
     *
     * if (!x)
     *   (x: null)
     *   throw new Exception -- effects: remove null from the type of x
     *
     * @param  StatementsChecker       $statements_checker
     * @param  PhpParser\Node\Stmt\If_ $stmt
     * @param  Context                 $context
     * @param  Context|null            $loop_context
     * @return null|false
     */
    public static function check(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\If_ $stmt,
        Context $context,
        Context $loop_context = null
    ) {
        // get the first expression in the if, which should be evaluated on its own
        // this allows us to update the context of $matches in
        // if (!preg_match('/a/', 'aa', $matches)) {
        //   exit
        // }
        // echo $matches[0];
        $first_if_cond_expr = self::getFirstFunctionCall($stmt->cond);

        if ($first_if_cond_expr &&
            ExpressionChecker::check($statements_checker, $first_if_cond_expr, $context) === false
        ) {
            return false;
        }

        $if_scope = new IfScope();

        $if_scope->loop_context = $loop_context;

        $if_context = clone $context;

        // we need to clone the current context so our ongoing updates to $context don't mess with elseif/else blocks
        $original_context = clone $context;

        if ($first_if_cond_expr !== $stmt->cond &&
            ExpressionChecker::check($statements_checker, $stmt->cond, $if_context) === false
        ) {
            return false;
        }

        $reconcilable_if_types = null;

        if ($stmt->cond instanceof PhpParser\Node\Expr\BinaryOp) {
            $reconcilable_if_types = TypeChecker::getReconcilableTypeAssertions(
                $stmt->cond,
                $statements_checker->getFQCLN(),
                $statements_checker->getNamespace(),
                $statements_checker->getAliasedClasses()
            );

            $if_scope->negatable_if_types = TypeChecker::getNegatableTypeAssertions(
                $stmt->cond,
                $statements_checker->getFQCLN(),
                $statements_checker->getNamespace(),
                $statements_checker->getAliasedClasses()
            );
        } else {
            $reconcilable_if_types = TypeChecker::getTypeAssertions(
                $stmt->cond,
                $statements_checker->getFQCLN(),
                $statements_checker->getNamespace(),
                $statements_checker->getAliasedClasses()
            );

            $if_scope->negatable_if_types = $reconcilable_if_types;
        }

        $if_scope->negated_types = $if_scope->negatable_if_types
                                    ? TypeChecker::negateTypes($if_scope->negatable_if_types)
                                    : [];

        // if the if has an || in the conditional, we cannot easily reason about it
        if ($reconcilable_if_types) {
            $if_vars_in_scope_reconciled =
                TypeChecker::reconcileKeyedTypes(
                    $reconcilable_if_types,
                    $if_context->vars_in_scope,
                    new CodeLocation($statements_checker->getSource(), $stmt),
                    $statements_checker->getSuppressedIssues()
                );

            if ($if_vars_in_scope_reconciled === false) {
                return false;
            }

            $if_context->vars_in_scope = $if_vars_in_scope_reconciled;
            $if_context->vars_possibly_in_scope = array_merge(
                $reconcilable_if_types,
                $if_context->vars_possibly_in_scope
            );
        }

        $old_if_context = clone $if_context;
        $context->vars_possibly_in_scope = array_merge(
            $if_context->vars_possibly_in_scope,
            $context->vars_possibly_in_scope
        );

        $temp_else_context = clone $original_context;

        if ($if_scope->negated_types) {
            $else_vars_reconciled = TypeChecker::reconcileKeyedTypes(
                $if_scope->negated_types,
                $temp_else_context->vars_in_scope,
                new CodeLocation($statements_checker->getSource(), $stmt),
                $statements_checker->getSuppressedIssues()
            );

            if ($else_vars_reconciled === false) {
                return false;
            }

            $temp_else_context->vars_in_scope = $else_vars_reconciled;
        }

        // we calculate the vars redefined in a hypothetical else statement to determine
        // which vars of the if we can safely change
        $pre_assignment_else_redefined_vars = Context::getRedefinedVars($context, $temp_else_context);

        // check the if
        self::checkIfBlock(
            $statements_checker,
            $stmt,
            $if_scope,
            $if_context,
            $old_if_context,
            $context,
            $pre_assignment_else_redefined_vars
        );

        // check the elseifs
        foreach ($stmt->elseifs as $elseif) {
            self::checkElseIfBlock(
                $statements_checker,
                $elseif,
                $if_scope,
                clone $original_context,
                $context
            );
        }

        // check the else
        if ($stmt->else) {
            self::checkElseBlock(
                $statements_checker,
                $stmt->else,
                $if_scope,
                clone $original_context,
                $context
            );
        }

        $context->vars_possibly_in_scope = array_merge($context->vars_possibly_in_scope, $if_scope->new_vars_possibly_in_scope);

        $updated_loop_vars = [];

        // vars can only be defined/redefined if there was an else (defined in every block)
        if ($stmt->else) {
            if ($if_scope->new_vars) {
                $context->vars_in_scope = array_merge($context->vars_in_scope, $if_scope->new_vars);
            }

            if ($if_scope->redefined_vars) {
                foreach ($if_scope->redefined_vars as $var => $type) {
                    $context->vars_in_scope[$var] = $type;
                    $if_scope->updated_vars[$var] = true;
                }
            }

            if ($if_scope->redefined_loop_vars && $loop_context) {
                foreach ($if_scope->redefined_loop_vars as $var => $type) {
                    $loop_context->vars_in_scope[$var] = $type;
                    $updated_loop_vars[$var] = true;
                }
            }
        } else {
            if ($if_scope->forced_new_vars) {
                $context->vars_in_scope = array_merge($context->vars_in_scope, $if_scope->forced_new_vars);
            }
        }

        if ($if_scope->possibly_redefined_vars) {
            foreach ($if_scope->possibly_redefined_vars as $var => $type) {
                if (isset($context->vars_in_scope[$var]) && !isset($if_scope->updated_vars[$var])) {
                    $context->vars_in_scope[$var] = Type::combineUnionTypes($context->vars_in_scope[$var], $type);
                }
            }
        }

        if ($if_scope->possibly_redefined_loop_vars && $loop_context) {
            foreach ($if_scope->possibly_redefined_loop_vars as $var => $type) {
                if (isset($loop_context->vars_in_scope[$var]) && !isset($updated_loop_vars[$var])) {
                    $loop_context->vars_in_scope[$var] = Type::combineUnionTypes(
                        $loop_context->vars_in_scope[$var],
                        $type
                    );
                }
            }
        }

        return null;
    }

    /**
     * @param  StatementsChecker        $statements_checker
     * @param  PhpParser\Node\Stmt\If_  $stmt
     * @param  IfScope                  $if_scope
     * @param  Context                  $if_context
     * @param  Context                  $old_if_context
     * @param  Context                  $outer_context
     * @param  array<string,Type\Union> $pre_assignment_else_redefined_vars
     * @return false|null
     */
    protected static function checkIfBlock(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\If_ $stmt,
        IfScope $if_scope,
        Context $if_context,
        Context $old_if_context,
        Context $outer_context,
        array $pre_assignment_else_redefined_vars
    ) {
        $has_ending_statements = ScopeChecker::doesAlwaysReturnOrThrow($stmt->stmts);

        $has_leaving_statements = $has_ending_statements || ScopeChecker::doesAlwaysBreakOrContinue($stmt->stmts);

        if ($statements_checker->check($stmt->stmts, $if_context, $if_scope->loop_context) === false) {
            return false;
        }

        $mic_drop = false;

        if (!$has_leaving_statements) {
            $if_scope->new_vars = array_diff_key($if_context->vars_in_scope, $outer_context->vars_in_scope);

            // if we have a check like if (!isset($a)) { $a = true; } we want to make sure $a is always set
            foreach ($if_scope->new_vars as $var_id => $type) {
                if (isset($if_scope->negated_types[$var_id]) && $if_scope->negated_types[$var_id] === '!null') {
                    $if_scope->forced_new_vars[$var_id] = Type::getMixed();
                }
            }

            $if_scope->redefined_vars = Context::getRedefinedVars($outer_context, $if_context);
            $if_scope->possibly_redefined_vars = $if_scope->redefined_vars;
        } elseif (!$stmt->else && !$stmt->elseifs && $if_scope->negated_types) {
            $outer_context_vars_reconciled = TypeChecker::reconcileKeyedTypes(
                $if_scope->negated_types,
                $outer_context->vars_in_scope,
                new CodeLocation($statements_checker->getSource(), $stmt),
                $statements_checker->getSuppressedIssues()
            );

            if ($outer_context_vars_reconciled === false) {
                return false;
            }

            $outer_context->vars_in_scope = $outer_context_vars_reconciled;
            $mic_drop = true;
        }

        // update the parent context as necessary, but only if we can safely reason about type negation.
        // We only update vars that changed both at the start of the if block and then again by an assignment
        // in the if statement.
        if ($if_scope->negatable_if_types && !$mic_drop) {
            $outer_context->update(
                $old_if_context,
                $if_context,
                $has_leaving_statements,
                array_intersect(array_keys($pre_assignment_else_redefined_vars), array_keys($if_scope->negatable_if_types)),
                $if_scope->updated_vars
            );
        }

        if (!$has_ending_statements) {
            $vars = array_diff_key($if_context->vars_possibly_in_scope, $outer_context->vars_possibly_in_scope);

            if ($has_leaving_statements && $if_scope->loop_context) {
                $if_scope->redefined_loop_vars = Context::getRedefinedVars($if_scope->loop_context, $if_context);
                $if_scope->possibly_redefined_loop_vars = $if_scope->redefined_loop_vars;
            }

            // if we're leaving this block, add vars to outer for loop scope
            if ($has_leaving_statements) {
                if ($if_scope->loop_context) {
                    $if_scope->loop_context->vars_possibly_in_scope = array_merge(
                        $if_scope->loop_context->vars_possibly_in_scope,
                        $vars
                    );
                }
            } else {
                $if_scope->new_vars_possibly_in_scope = $vars;
            }
        }
    }

    /**
     * @param  StatementsChecker           $statements_checker
     * @param  PhpParser\Node\Stmt\ElseIf_ $elseif
     * @param  IfScope                     $if_scope
     * @param  Context                     $elseif_context
     * @param  Context                     $outer_context
     * @return false|null
     */
    protected static function checkElseIfBlock(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\ElseIf_ $elseif,
        IfScope $if_scope,
        Context $elseif_context,
        Context $outer_context
    ) {
        $original_context = clone $elseif_context;

        if ($if_scope->negated_types) {
            $elseif_vars_reconciled = TypeChecker::reconcileKeyedTypes(
                $if_scope->negated_types,
                $elseif_context->vars_in_scope,
                new CodeLocation($statements_checker->getSource(), $elseif),
                $statements_checker->getSuppressedIssues()
            );

            if ($elseif_vars_reconciled === false) {
                return false;
            }

            $elseif_context->vars_in_scope = $elseif_vars_reconciled;
        }

        if ($elseif->cond instanceof PhpParser\Node\Expr\BinaryOp) {
            $reconcilable_elseif_types = TypeChecker::getReconcilableTypeAssertions(
                $elseif->cond,
                $statements_checker->getFQCLN(),
                $statements_checker->getNamespace(),
                $statements_checker->getAliasedClasses()
            );

            $negatable_elseif_types = TypeChecker::getNegatableTypeAssertions(
                $elseif->cond,
                $statements_checker->getFQCLN(),
                $statements_checker->getNamespace(),
                $statements_checker->getAliasedClasses()
            );
        } else {
            $reconcilable_elseif_types = $negatable_elseif_types = TypeChecker::getTypeAssertions(
                $elseif->cond,
                $statements_checker->getFQCLN(),
                $statements_checker->getNamespace(),
                $statements_checker->getAliasedClasses()
            );
        }

        // check the elseif
        if (ExpressionChecker::check($statements_checker, $elseif->cond, $elseif_context) === false) {
            return false;
        }

        $negated_elseif_types = $negatable_elseif_types
                                ? TypeChecker::negateTypes($negatable_elseif_types)
                                : [];

        $all_negated_vars = array_unique(
            array_merge(
                array_keys($negated_elseif_types),
                array_keys($if_scope->negated_types)
            )
        );

        foreach ($all_negated_vars as $var_id) {
            if (isset($negated_elseif_types[$var_id])) {
                if (isset($if_scope->negated_types[$var_id])) {
                    $if_scope->negated_types[$var_id] = $if_scope->negated_types[$var_id] . '&' . $negated_elseif_types[$var_id];
                } else {
                    $if_scope->negated_types[$var_id] = $negated_elseif_types[$var_id];
                }
            }
        }

        // if the elseif has an || in the conditional, we cannot easily reason about it
        if ($reconcilable_elseif_types) {
            $elseif_vars_reconciled = TypeChecker::reconcileKeyedTypes(
                $reconcilable_elseif_types,
                $elseif_context->vars_in_scope,
                new CodeLocation($statements_checker->getSource(), $elseif),
                $statements_checker->getSuppressedIssues()
            );

            if ($elseif_vars_reconciled === false) {
                return false;
            }

            $elseif_context->vars_in_scope = $elseif_vars_reconciled;
        }

        $old_elseif_context = clone $elseif_context;

        if ($statements_checker->check($elseif->stmts, $elseif_context, $if_scope->loop_context) === false) {
            return false;
        }

        if (count($elseif->stmts)) {
            // has a return/throw at end
            $has_ending_statements = ScopeChecker::doesAlwaysReturnOrThrow($elseif->stmts);

            $has_leaving_statements = $has_ending_statements ||
                ScopeChecker::doesAlwaysBreakOrContinue($elseif->stmts);

            // update the parent context as necessary
            $elseif_redefined_vars = Context::getRedefinedVars($original_context, $elseif_context);

            if (!$has_leaving_statements) {
                if ($if_scope->new_vars === null) {
                    $if_scope->new_vars = array_diff_key($elseif_context->vars_in_scope, $outer_context->vars_in_scope);
                } else {
                    foreach ($if_scope->new_vars as $new_var => $type) {
                        if (!isset($elseif_context->vars_in_scope[$new_var])) {
                            unset($if_scope->new_vars[$new_var]);
                        } else {
                            $if_scope->new_vars[$new_var] = Type::combineUnionTypes(
                                $type,
                                $elseif_context->vars_in_scope[$new_var]
                            );
                        }
                    }
                }

                if ($if_scope->redefined_vars === null) {
                    $if_scope->redefined_vars = $elseif_redefined_vars;
                    $if_scope->possibly_redefined_vars = $if_scope->redefined_vars;
                } else {
                    foreach ($if_scope->redefined_vars as $redefined_var => $type) {
                        if (!isset($elseif_redefined_vars[$redefined_var])) {
                            unset($if_scope->redefined_vars[$redefined_var]);
                        } else {
                            $if_scope->redefined_vars[$redefined_var] = Type::combineUnionTypes(
                                $elseif_redefined_vars[$redefined_var],
                                $type
                            );
                        }
                    }

                    foreach ($elseif_redefined_vars as $var => $type) {
                        if ($type->isMixed()) {
                            $if_scope->possibly_redefined_vars[$var] = $type;
                        } elseif (isset($if_scope->possibly_redefined_vars[$var])) {
                            $if_scope->possibly_redefined_vars[$var] = Type::combineUnionTypes(
                                $type,
                                $if_scope->possibly_redefined_vars[$var]
                            );
                        } else {
                            $if_scope->possibly_redefined_vars[$var] = $type;
                        }
                    }
                }
            }

            if ($negatable_elseif_types) {
                $outer_context->update(
                    $old_elseif_context,
                    $elseif_context,
                    $has_leaving_statements,
                    array_keys($negated_elseif_types),
                    $if_scope->updated_vars
                );
            }

            if (!$has_ending_statements) {
                $vars = array_diff_key($elseif_context->vars_possibly_in_scope, $outer_context->vars_possibly_in_scope);

                // if we're leaving this block, add vars to outer for loop scope
                if ($has_leaving_statements && $if_scope->loop_context) {
                    if ($if_scope->redefined_loop_vars === null) {
                        $if_scope->redefined_loop_vars = $elseif_redefined_vars;
                        $if_scope->possibly_redefined_loop_vars = $if_scope->redefined_loop_vars;
                    } else {
                        foreach ($if_scope->redefined_loop_vars as $redefined_var => $type) {
                            if (!isset($elseif_redefined_vars[$redefined_var])) {
                                unset($if_scope->redefined_loop_vars[$redefined_var]);
                            } else {
                                $if_scope->redefined_loop_vars[$redefined_var] = Type::combineUnionTypes(
                                    $elseif_redefined_vars[$redefined_var],
                                    $type
                                );
                            }
                        }

                        foreach ($elseif_redefined_vars as $var => $type) {
                            if ($type->isMixed()) {
                                $if_scope->possibly_redefined_loop_vars[$var] = $type;
                            } elseif (isset($if_scope->possibly_redefined_loop_vars[$var])) {
                                $if_scope->possibly_redefined_loop_vars[$var] = Type::combineUnionTypes(
                                    $type,
                                    $if_scope->possibly_redefined_loop_vars[$var]
                                );
                            } else {
                                $if_scope->possibly_redefined_loop_vars[$var] = $type;
                            }
                        }
                    }

                    $if_scope->loop_context->vars_possibly_in_scope = array_merge(
                        $vars,
                        $if_scope->loop_context->vars_possibly_in_scope
                    );
                } elseif (!$has_leaving_statements) {
                    $if_scope->new_vars_possibly_in_scope = array_merge($vars, $if_scope->new_vars_possibly_in_scope);
                }
            }
        }
    }

    /**
     * @param  StatementsChecker         $statements_checker
     * @param  PhpParser\Node\Stmt\Else_ $else
     * @param  IfScope                   $if_scope
     * @param  Context                   $else_context
     * @param  Context                   $outer_context
     * @return false|null
     */
    protected static function checkElseBlock(
        StatementsChecker $statements_checker,
        PhpParser\Node\Stmt\Else_ $else,
        IfScope $if_scope,
        Context $else_context,
        Context $outer_context
    ) {
        $original_context = clone $else_context;

        if ($if_scope->negated_types) {
            $else_vars_reconciled = TypeChecker::reconcileKeyedTypes(
                $if_scope->negated_types,
                $else_context->vars_in_scope,
                new CodeLocation($statements_checker->getSource(), $else),
                $statements_checker->getSuppressedIssues()
            );

            if ($else_vars_reconciled === false) {
                return false;
            }

            $else_context->vars_in_scope = $else_vars_reconciled;
        }

        $old_else_context = clone $else_context;

        if ($statements_checker->check($else->stmts, $else_context, $if_scope->loop_context) === false) {
            return false;
        }

        if (count($else->stmts)) {
            // has a return/throw at end
            $has_ending_statements = ScopeChecker::doesAlwaysReturnOrThrow($else->stmts);

            $has_leaving_statements = $has_ending_statements ||
                ScopeChecker::doesAlwaysBreakOrContinue($else->stmts);

            $else_redefined_vars = Context::getRedefinedVars($original_context, $else_context);

            // if it doesn't end in a return
            if (!$has_leaving_statements) {
                if ($if_scope->new_vars === null) {
                    $if_scope->new_vars = array_diff_key($else_context->vars_in_scope, $outer_context->vars_in_scope);
                } else {
                    foreach ($if_scope->new_vars as $new_var => $type) {
                        if (!isset($else_context->vars_in_scope[$new_var])) {
                            unset($if_scope->new_vars[$new_var]);
                        } else {
                            $if_scope->new_vars[$new_var] = Type::combineUnionTypes(
                                $type,
                                $else_context->vars_in_scope[$new_var]
                            );
                        }
                    }
                }

                if ($if_scope->redefined_vars === null) {
                    $if_scope->redefined_vars = $else_redefined_vars;
                    $if_scope->possibly_redefined_vars = $if_scope->redefined_vars;
                } else {
                    foreach ($if_scope->redefined_vars as $redefined_var => $type) {
                        if (!isset($else_redefined_vars[$redefined_var])) {
                            unset($if_scope->redefined_vars[$redefined_var]);
                        } else {
                            $if_scope->redefined_vars[$redefined_var] = Type::combineUnionTypes(
                                $else_redefined_vars[$redefined_var],
                                $type
                            );
                        }
                    }

                    foreach ($else_redefined_vars as $var => $type) {
                        if ($type->isMixed()) {
                            $if_scope->possibly_redefined_vars[$var] = $type;
                        } elseif (isset($if_scope->possibly_redefined_vars[$var])) {
                            $if_scope->possibly_redefined_vars[$var] = Type::combineUnionTypes(
                                $type,
                                $if_scope->possibly_redefined_vars[$var]
                            );
                        } else {
                            $if_scope->possibly_redefined_vars[$var] = $type;
                        }
                    }
                }
            }

            // update the parent context as necessary
            if ($if_scope->negatable_if_types) {
                $outer_context->update(
                    $old_else_context,
                    $else_context,
                    $has_leaving_statements,
                    array_keys($if_scope->negatable_if_types),
                    $if_scope->updated_vars
                );
            }

            if (!$has_ending_statements) {
                $vars = array_diff_key($else_context->vars_possibly_in_scope, $outer_context->vars_possibly_in_scope);

                if ($has_leaving_statements && $if_scope->loop_context) {
                    if ($if_scope->redefined_loop_vars === null) {
                        $if_scope->redefined_loop_vars = $else_redefined_vars;
                        $if_scope->possibly_redefined_loop_vars = $if_scope->redefined_loop_vars;
                    } else {
                        foreach ($if_scope->redefined_loop_vars as $redefined_var => $type) {
                            if (!isset($else_redefined_vars[$redefined_var])) {
                                unset($if_scope->redefined_loop_vars[$redefined_var]);
                            } else {
                                $if_scope->redefined_loop_vars[$redefined_var] = Type::combineUnionTypes(
                                    $else_redefined_vars[$redefined_var],
                                    $type
                                );
                            }
                        }

                        foreach ($else_redefined_vars as $var => $type) {
                            if ($type->isMixed()) {
                                $if_scope->possibly_redefined_loop_vars[$var] = $type;
                            } elseif (isset($if_scope->possibly_redefined_loop_vars[$var])) {
                                $if_scope->possibly_redefined_loop_vars[$var] = Type::combineUnionTypes(
                                    $type,
                                    $if_scope->possibly_redefined_loop_vars[$var]
                                );
                            } else {
                                $if_scope->possibly_redefined_loop_vars[$var] = $type;
                            }
                        }
                    }

                    $if_scope->loop_context->vars_possibly_in_scope = array_merge(
                        $vars,
                        $if_scope->loop_context->vars_possibly_in_scope
                    );
                } elseif (!$has_leaving_statements) {
                    $if_scope->new_vars_possibly_in_scope = array_merge($vars, $if_scope->new_vars_possibly_in_scope);
                }
            }
        }
    }

    /**
     * @param  PhpParser\Node\Expr $stmt
     * @return PhpParser\Node\Expr|null
     */
    protected static function getFirstFunctionCall(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\MethodCall
            || $stmt instanceof PhpParser\Node\Expr\StaticCall
            || $stmt instanceof PhpParser\Node\Expr\FuncCall
        ) {
            return $stmt;
        }

        if ($stmt instanceof PhpParser\Node\Expr\BinaryOp) {
            return self::getFirstFunctionCall($stmt->left);
        }

        if ($stmt instanceof PhpParser\Node\Expr\BooleanNot) {
            return self::getFirstFunctionCall($stmt->expr);
        }

        return null;
    }
}
