<?php

require_once __DIR__ . '/Ast.php';

class Monomorphizer {
    private array $generic_fns     = [];
    private array $generic_structs = [];
    private array $generic_impls   = [];
    private array $concrete_fns     = [];
    private array $concrete_structs = [];
    private array $concrete_impls   = [];
    private array $concrete_enums   = [];

    private array $fn_instances     = [];
    private array $struct_instances = [];
    private array $impl_instances   = [];

    private array $fn_type_params     = [];
    private array $struct_type_params = [];
    private array $var_types          = [];

    private array $traits      = [];
    private array $trait_impls = [];

    public function monomorphize(ProgramNode $program): ProgramNode {
        foreach ($program->functions as $fn) {
            if (!empty($fn->type_params)) {
                $this->generic_fns[$fn->name] = $fn;
                $this->fn_type_params[$fn->name] = $fn->type_params;
            } else {
                $this->concrete_fns[] = $fn;
            }
        }

        foreach ($program->structs as $sd) {
            if (!empty($sd->type_params)) {
                $this->generic_structs[$sd->name] = $sd;
                $this->struct_type_params[$sd->name] = $sd->type_params;
            } else {
                $this->concrete_structs[] = $sd;
            }
        }

        foreach ($program->impls as $impl) {
            if (!empty($impl->type_params)) {
                $this->generic_impls[] = $impl;
            } else {
                $this->concrete_impls[] = $impl;
            }
        }

        $this->concrete_enums = $program->enums;

        foreach ($program->traits as $trait) {
            $this->traits[$trait->name] = $trait;
        }

        $this->fillTraitDefaults($this->concrete_impls);
        $this->fillTraitDefaults($this->generic_impls);

        foreach ($this->concrete_impls as $impl) {
            if ($impl->trait_name !== null) {
                $this->trait_impls[$impl->trait_name][$impl->struct_name] = true;
            }
        }
        foreach ($this->generic_impls as $impl) {
            if ($impl->trait_name !== null) {
                $base = $this->stripGeneric($impl->struct_name);
                $this->trait_impls[$impl->trait_name]['__generic__' . $base] = true;
            }
        }

        $this->collectInstantiations($this->concrete_fns);
        foreach ($this->concrete_impls as $impl) {
            $this->collectInstantiations($impl->functions);
        }

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->fn_instances as $key => [$name, $map]) {
                $mangled = $this->mangleFn($name, $map);
                if (!$this->hasConcreteFunction($mangled)) {
                    $this->emitFunction($name, $map);
                    $changed = true;
                }
            }
            foreach ($this->struct_instances as $key => [$name, $map]) {
                $mangled = $this->mangleStruct($name, $map);
                if (!$this->hasConcreteStruct($mangled)) {
                    $this->emitStruct($name, $map);
                    $changed = true;
                }
            }
            foreach ($this->impl_instances as $key => [$impl_idx, $map]) {
                $impl = $this->generic_impls[$impl_idx];
                $base_name = $this->stripGeneric($impl->struct_name);
                $mangled_struct = $this->mangleStruct($base_name, $map);
                if (!$this->hasConcreteImpl($mangled_struct)) {
                    $this->emitImpl($impl_idx, $map);
                    $changed = true;
                }
            }
        }

        foreach ($this->concrete_fns as &$fn) {
            $fn = $this->rewriteFunctionSignature($fn);
            $this->var_types = [];
            foreach ($fn->params as $p) {
                $this->var_types[$p['name']] = preg_replace('/^&(mut )?/', '', $p['type']);
            }
            $fn->body = $this->rewriteBody($fn->body);
        }
        unset($fn);
        foreach ($this->concrete_impls as &$impl) {
            foreach ($impl->functions as &$fn) {
                $fn = $this->rewriteFunctionSignature($fn);
                $this->var_types = [];
                foreach ($fn->params as $p) {
                    $this->var_types[$p['name']] = preg_replace('/^&(mut )?/', '', $p['type']);
                }
                $fn->body = $this->rewriteBody($fn->body);
            }
            unset($fn);
        }
        unset($impl);

        $consts = [];
        foreach ($program->consts as $c) {
            $consts[] = new ConstItemNode($c->name, $this->rewriteTypeName($c->type), $this->rewriteExpr($c->value), $c->line);
        }
        $statics = [];
        foreach ($program->statics as $s) {
            $statics[] = new StaticItemNode($s->name, $this->rewriteTypeName($s->type), $this->rewriteExpr($s->value), $s->mutable, $s->line);
        }
        return new ProgramNode(
            $this->concrete_fns,
            $this->concrete_structs,
            $this->concrete_impls,
            $this->concrete_enums,
            [],
            [],
            array_values($this->traits),
            $consts,
            $statics
        );
    }

    private function stripGeneric(string $name): string {
        $pos = strpos($name, '<');
        return $pos !== false ? substr($name, 0, $pos) : $name;
    }

    private function extractGenericArg(string $name): ?string {
        if (preg_match('/<(.+)>/', $name, $m)) return $m[1];
        return null;
    }

    private function mangleFn(string $name, array $map): string {
        return $name . '__' . implode('_', $map);
    }

    private function mangleStruct(string $name, array $map): string {
        return $name . '__' . implode('_', $map);
    }

    private function hasConcreteFunction(string $name): bool {
        foreach ($this->concrete_fns as $fn) {
            if ($fn->name === $name) return true;
        }
        return false;
    }

    private function hasConcreteStruct(string $name): bool {
        foreach ($this->concrete_structs as $sd) {
            if ($sd->name === $name) return true;
        }
        return false;
    }

    private function hasConcreteImpl(string $struct_name): bool {
        foreach ($this->concrete_impls as $impl) {
            if ($impl->struct_name === $struct_name) return true;
        }
        return false;
    }

    private function fillTraitDefaults(array &$impls): void {
        foreach ($impls as &$impl) {
            if ($impl->trait_name === null) continue;
            if (!isset($this->traits[$impl->trait_name])) continue;
            $trait = $this->traits[$impl->trait_name];

            $provided = [];
            foreach ($impl->functions as $fn) {
                $provided[$fn->name] = true;
            }

            foreach ($trait->methods as $trait_method) {
                if (isset($provided[$trait_method->name])) continue;
                if ($trait_method->body !== null) {
                    $impl->functions[] = clone $trait_method;
                } else {
                    throw new RuntimeException(
                        "Type '{$impl->struct_name}' does not implement required method '{$trait_method->name}' of trait '{$impl->trait_name}' on line {$impl->line}"
                    );
                }
            }
        }
        unset($impl);
    }

    private function validateBounds(FunctionNode $fn, array $map): void {
        foreach ($fn->type_bounds as $param => $bounds) {
            $concrete = $map[$param] ?? null;
            if ($concrete === null) continue;
            foreach ($bounds as $trait_name) {
                if (!$this->typeImplementsTrait($concrete, $trait_name)) {
                    throw new RuntimeException(
                        "Type '$concrete' does not implement trait '$trait_name'"
                    );
                }
            }
        }
    }

    private function typeImplementsTrait(string $type, string $trait_name): bool {
        if (isset($this->trait_impls[$trait_name][$type])) return true;
        foreach ($this->trait_impls[$trait_name] ?? [] as $key => $v) {
            if (str_starts_with($key, '__generic__')) {
                $base = substr($key, strlen('__generic__'));
                if (str_starts_with($type, $base . '__') || $type === $base) return true;
            }
        }
        return false;
    }

    private function collectInstantiations(array $fns): void {
        foreach ($fns as $fn) {
            if ($fn->body === null) continue;
            $this->var_types = [];
            foreach ($fn->params as $p) {
                $this->registerTypeUsage($p['type']);
                $this->var_types[$p['name']] = preg_replace('/^&(mut )?/', '', $p['type']);
            }
            if ($fn->return_type !== null) {
                $this->registerTypeUsage($fn->return_type);
            }
            $this->scanBody($fn->body);
        }
    }

    private function scanBody(array $stmts): void {
        foreach ($stmts as $stmt) {
            $this->scanStmt($stmt);
        }
    }

    private function scanStmt(mixed $stmt): void {
        if ($stmt instanceof LetNode) {
            if ($stmt->type_name !== null) {
                $this->registerTypeUsage($stmt->type_name);
            }
            $this->scanExpr($stmt->value);
            $inferred = $stmt->type_name !== null
                ? preg_replace('/^&(mut )?/', '', $stmt->type_name)
                : $this->guessExprType($stmt->value);
            if ($inferred !== null) {
                if (!empty($stmt->bindings)) {
                    $element_types = $this->tupleElementTypes($inferred);
                    if ($element_types !== null) {
                        foreach ($stmt->bindings as $i => $name) {
                            if (isset($element_types[$i])) $this->var_types[$name] = $element_types[$i];
                        }
                    }
                } else {
                    $this->var_types[$stmt->name] = $inferred;
                }
            }
        } elseif ($stmt instanceof AssignNode) {
            $this->scanExpr($stmt->value);
        } elseif ($stmt instanceof CompoundAssignNode) {
            $this->scanExpr($stmt->target);
            $this->scanExpr($stmt->value);
        } elseif ($stmt instanceof FieldAssignNode) {
            $this->scanExpr($stmt->value);
        } elseif ($stmt instanceof DerefAssignNode) {
            $this->scanExpr($stmt->value);
        } elseif ($stmt instanceof ReturnNode) {
            if ($stmt->value !== null) $this->scanExpr($stmt->value);
        } elseif ($stmt instanceof ExprStmtNode) {
            $this->scanExpr($stmt->expr);
        } elseif ($stmt instanceof PrintlnNode) {
            foreach ($stmt->parts as $part) {
                if (!is_string($part)) $this->scanExpr($part);
            }
        } elseif ($stmt instanceof IfNode) {
            $this->scanExpr($stmt->condition);
            $this->scanBody($stmt->then_body);
            if ($stmt->else_body !== null) $this->scanBody($stmt->else_body);
        } elseif ($stmt instanceof IfLetNode) {
            $this->scanExpr($stmt->subject);
            $this->scanBody($stmt->then_body);
            if ($stmt->else_body !== null) $this->scanBody($stmt->else_body);
        } elseif ($stmt instanceof WhileNode) {
            $this->scanExpr($stmt->condition);
            $this->scanBody($stmt->body);
        } elseif ($stmt instanceof WhileLetNode) {
            $this->scanExpr($stmt->subject);
            $this->scanBody($stmt->body);
        } elseif ($stmt instanceof LoopNode) {
            $this->scanBody($stmt->body);
        } elseif ($stmt instanceof MatchNode) {
            $this->scanExpr($stmt->subject);
            foreach ($stmt->arms as $arm) {
                $this->scanBody($arm->body);
            }
        }
    }

    private function scanExpr(mixed $expr): void {
        if ($expr instanceof CallNode) {
            foreach ($expr->args as $arg) $this->scanExpr($arg);
            $base_name = $expr->name;
            $pos = strpos($base_name, '::');
            if ($pos !== false) $base_name = substr($base_name, $pos + 2);
            $struct_prefix = $pos !== false ? substr($expr->name, 0, $pos) : null;

            if ($struct_prefix !== null && $this->extractGenericArg($struct_prefix) !== null) {
                $this->registerTypeUsage($struct_prefix);
            }

            if ($struct_prefix !== null && isset($this->generic_structs[$struct_prefix])) {
                $method_fn = $this->findImplMethod($struct_prefix, $base_name);
                if ($method_fn !== null) {
                    $map = $this->inferImplTypeMap($struct_prefix, $method_fn, $expr->args);
                    if ($map !== null) {
                        $tp  = $this->struct_type_params[$struct_prefix];
                        $arg = $map[$tp[0]];
                        $this->registerTypeUsage("$struct_prefix<$arg>");
                    }
                }
            }

            if (isset($this->generic_fns[$expr->name])) {
                $fn_def = $this->generic_fns[$expr->name];
                $map = $this->inferTypeMap($fn_def, $expr->args);
                if ($map !== null) {
                    $this->validateBounds($fn_def, $map);
                    $key = $expr->name . '<' . implode(',', $map) . '>';
                    $this->fn_instances[$key] = [$expr->name, $map];
                }
            }
        } elseif ($expr instanceof StructLitNode) {
            foreach ($expr->fields as $f) $this->scanExpr($f['value']);
            $base = $this->stripGeneric($expr->struct_name);
            if (isset($this->generic_structs[$base])) {
                $arg = $this->extractGenericArg($expr->struct_name);
                if ($arg !== null) {
                    $this->registerTypeUsage($expr->struct_name);
                } else {
                    $inferred = $this->inferStructType($base, $expr->fields);
                    if ($inferred !== null) {
                        $this->registerTypeUsage("$base<$inferred>");
                    }
                }
            } else {
                $this->registerTypeUsage($expr->struct_name);
            }
        } elseif ($expr instanceof MethodCallNode) {
            $this->scanExpr($expr->receiver);
            foreach ($expr->args as $arg) $this->scanExpr($arg);
        } elseif ($expr instanceof BinaryOpNode) {
            $this->scanExpr($expr->left);
            $this->scanExpr($expr->right);
        } elseif ($expr instanceof UnaryOpNode) {
            $this->scanExpr($expr->operand);
        } elseif ($expr instanceof BorrowNode) {
            $this->scanExpr($expr->operand);
        } elseif ($expr instanceof DerefNode) {
            $this->scanExpr($expr->operand);
        } elseif ($expr instanceof FieldAccessNode) {
            $this->scanExpr($expr->object);
        } elseif ($expr instanceof TupleLitNode) {
            foreach ($expr->elements as $e) $this->scanExpr($e);
        } elseif ($expr instanceof TupleIndexNode) {
            $this->scanExpr($expr->object);
        } elseif ($expr instanceof CastNode) {
            $this->scanExpr($expr->expr);
        } elseif ($expr instanceof IndexNode) {
            $this->scanExpr($expr->object);
            $this->scanExpr($expr->index);
        } elseif ($expr instanceof IfNode) {
            $this->scanStmt($expr);
        } elseif ($expr instanceof IfLetNode) {
            $this->scanStmt($expr);
        } elseif ($expr instanceof MatchNode) {
            $this->scanStmt($expr);
        } elseif ($expr instanceof EnumVariantNode) {
            foreach ($expr->args as $arg) $this->scanExpr($arg);
        }
    }

    private function registerTypeUsage(string $type): void {
        $type = preg_replace('/^&(mut )?/', '', $type);
        $base = $this->stripGeneric($type);
        $arg  = $this->extractGenericArg($type);
        if ($arg === null) return;

        if (isset($this->generic_structs[$base])) {
            $tp  = $this->struct_type_params[$base];
            $map = [$tp[0] => $arg];
            $key = "$base<$arg>";
            $this->struct_instances[$key] = [$base, $map];

            foreach ($this->generic_impls as $idx => $impl) {
                $impl_base = $this->stripGeneric($impl->struct_name);
                if ($impl_base === $base) {
                    $ikey = "$idx<$arg>";
                    $this->impl_instances[$ikey] = [$idx, $map];
                }
            }
        }
    }

    private function findImplMethod(string $struct_name, string $method_name): ?FunctionNode {
        foreach ($this->generic_impls as $impl) {
            if ($this->stripGeneric($impl->struct_name) !== $struct_name) continue;
            foreach ($impl->functions as $fn) {
                if ($fn->name === $method_name) return $fn;
            }
        }
        return null;
    }

    private function inferImplTypeMap(string $struct_name, FunctionNode $fn, array $args): ?array {
        $tp = $this->struct_type_params[$struct_name] ?? [];
        if (empty($tp)) return null;
        $map = [];
        $arg_idx = 0;
        foreach ($fn->params as $param) {
            if ($param['type'] === 'self' || $param['type'] === '&self' || $param['type'] === '&mut self') continue;
            if (!isset($args[$arg_idx])) { $arg_idx++; continue; }
            $ptype = preg_replace('/^&(mut )?/', '', $param['type']);
            if (in_array($ptype, $tp)) {
                $concrete = $this->guessExprType($args[$arg_idx]);
                if ($concrete !== null) $map[$ptype] = $concrete;
            }
            $arg_idx++;
        }
        if (count($map) !== count($tp)) return null;
        return $map;
    }

    private function inferStructType(string $struct_name, array $lit_fields): ?string {
        $sd = $this->generic_structs[$struct_name];
        $tp = $this->struct_type_params[$struct_name];
        foreach ($sd->fields as $i => $f) {
            if (in_array($f['type'], $tp) && isset($lit_fields[$i])) {
                $concrete = $this->guessExprType($lit_fields[$i]['value']);
                if ($concrete !== null) return $concrete;
            }
        }
        return null;
    }

    private function inferTypeMap(FunctionNode $fn, array $args): ?array {
        $map = [];
        foreach ($fn->params as $i => $param) {
            if (!isset($args[$i])) continue;
            $ptype = $param['type'];
            $bare  = preg_replace('/^&(mut )?/', '', $ptype);
            $is_ref = ($ptype !== $bare);
            $arg_expr = $args[$i];
            if ($is_ref && $arg_expr instanceof BorrowNode) {
                $arg_expr = $arg_expr->operand;
            }
            $concrete = $this->guessExprType($arg_expr);
            if (in_array($bare, $fn->type_params)) {
                if ($concrete !== null) {
                    $map[$bare] = $concrete;
                }
            } elseif ($concrete !== null && str_contains($bare, '<')) {
                $inner = $this->extractGenericArg($bare);
                if ($inner !== null) {
                    $param_names = array_map('trim', explode(',', $inner));
                    $concrete_args = $this->getConcreteTypeArgs($concrete);
                    if ($concrete_args !== null && count($concrete_args) === count($param_names)) {
                        foreach ($param_names as $idx => $pname) {
                            if (in_array($pname, $fn->type_params)) {
                                $map[$pname] = $concrete_args[$idx];
                            }
                        }
                    }
                }
            }
        }
        if (count($map) !== count($fn->type_params)) return null;
        return $map;
    }

    private function getConcreteTypeArgs(string $concrete): ?array {
        $parts = explode('__', $concrete);
        if (count($parts) < 2) return null;
        return array_slice($parts, 1);
    }

    private function guessExprType(mixed $expr): ?string {
        if ($expr instanceof UnitLitNode) return '()';
        if ($expr instanceof TupleLitNode) {
            $parts = [];
            foreach ($expr->elements as $e) {
                $t = $this->guessExprType($e);
                $parts[] = $t ?? 'i32';
            }
            return '(' . implode(',', $parts) . ')';
        }
        if ($expr instanceof TupleIndexNode) {
            $obj = $this->guessExprType($expr->object);
            if ($obj === null) return null;
            $elements = $this->tupleElementTypes($obj);
            if ($elements !== null && isset($elements[$expr->index])) return $elements[$expr->index];
            return null;
        }
        if ($expr instanceof CastNode) return $expr->target_type;
        if ($expr instanceof IntLitNode) return 'i32';
        if ($expr instanceof BoolLitNode) return 'bool';
        if ($expr instanceof StringFromNode) return 'String';
        if ($expr instanceof IdentNode) return $this->var_types[$expr->name] ?? null;
        if ($expr instanceof BorrowNode) return $this->guessExprType($expr->operand);
        if ($expr instanceof BinaryOpNode) return 'i32';
        if ($expr instanceof UnaryOpNode) {
            if ($expr->op === '!') return 'bool';
            return 'i32';
        }
        if ($expr instanceof CallNode) {
            $parts = explode('::', $expr->name, 2);
            if (count($parts) === 2 && isset($this->generic_structs[$parts[0]]) && $parts[1] === 'new' && isset($expr->args[0])) {
                $arg_type = $this->guessExprType($expr->args[0]);
                if ($arg_type !== null) {
                    return $this->mangleStruct($parts[0], [$arg_type]);
                }
            }
            return null;
        }
        if ($expr instanceof StructLitNode) {
            $name = $expr->struct_name;
            $arg  = $this->extractGenericArg($name);
            if ($arg !== null) {
                $base = $this->stripGeneric($name);
                return $this->mangleStruct($base, [$arg]);
            }
            return $name;
        }
        return null;
    }

    private function emitFunction(string $name, array $map): void {
        $fn = $this->generic_fns[$name];
        $mangled = $this->mangleFn($name, $map);

        $new_params = [];
        foreach ($fn->params as $p) {
            $new_params[] = ['name' => $p['name'], 'type' => $this->substituteType($p['type'], $map)];
        }
        $new_return = $fn->return_type !== null ? $this->substituteType($fn->return_type, $map) : null;
        $new_body   = $this->substituteBody($fn->body, $map);
        $new_body   = $this->rewriteBody($new_body);

        $this->concrete_fns[] = new FunctionNode($mangled, $new_params, $new_return, $new_body, $fn->line, [], $fn->is_pub, $fn->module, []);
    }

    private function emitStruct(string $name, array $map): void {
        $sd = $this->generic_structs[$name];
        $mangled = $this->mangleStruct($name, $map);

        $new_fields = [];
        foreach ($sd->fields as $f) {
            $new_fields[] = ['name' => $f['name'], 'type' => $this->substituteType($f['type'], $map)];
        }

        $this->concrete_structs[] = new StructDefNode($mangled, $new_fields, $sd->line, [], $sd->is_pub, $sd->module);
    }

    private function emitImpl(int $impl_idx, array $map): void {
        $impl = $this->generic_impls[$impl_idx];
        $base_name = $this->stripGeneric($impl->struct_name);
        $mangled_struct = $this->mangleStruct($base_name, $map);

        $new_fns = [];
        foreach ($impl->functions as $fn) {
            $new_params = [];
            foreach ($fn->params as $p) {
                $new_params[] = ['name' => $p['name'], 'type' => $this->substituteType($p['type'], $map)];
            }
            $new_return = $fn->return_type !== null ? $this->substituteType($fn->return_type, $map) : null;
            $new_body   = $this->substituteBody($fn->body, $map);
            $new_body   = $this->rewriteBody($new_body);
            $new_fns[] = new FunctionNode($fn->name, $new_params, $new_return, $new_body, $fn->line, [], $fn->is_pub, $fn->module, []);
        }

        $this->concrete_impls[] = new ImplNode($mangled_struct, $new_fns, $impl->line, [], $impl->trait_name);
        if ($impl->trait_name !== null) {
            $this->trait_impls[$impl->trait_name][$mangled_struct] = true;
        }
    }

    private function tupleElementTypes(string $type): ?array {
        if (strlen($type) < 2 || $type[0] !== '(' || $type[strlen($type) - 1] !== ')') return null;
        $inner = substr($type, 1, -1);
        $depth = 0;
        $start = 0;
        $elements = [];
        for ($i = 0; $i <= strlen($inner); $i++) {
            $c = $i < strlen($inner) ? $inner[$i] : ',';
            if ($c === '(') $depth++;
            elseif ($c === ')') $depth--;
            elseif (($c === ',' && $depth === 0) || $i === strlen($inner)) {
                $seg = trim(substr($inner, $start, $i - $start));
                if ($seg !== '') $elements[] = $seg;
                $start = $i + 1;
            }
        }
        return $elements;
    }

    private function substituteType(string $type, array $map): string {
        $ref = '';
        if (str_starts_with($type, '&mut ')) { $ref = '&mut '; $type = substr($type, 5); }
        elseif (str_starts_with($type, '&'))  { $ref = '&';     $type = substr($type, 1); }

        if (str_starts_with($type, '*const ')) {
            return $ref . '*const ' . $this->substituteType(substr($type, 7), $map);
        }
        if (str_starts_with($type, '*mut ')) {
            return $ref . '*mut ' . $this->substituteType(substr($type, 5), $map);
        }

        $elements = $this->tupleElementTypes($type);
        if ($elements !== null) {
            $sub = [];
            foreach ($elements as $t) $sub[] = $this->substituteType($t, $map);
            return $ref . '(' . implode(',', $sub) . ')';
        }

        $base = $this->stripGeneric($type);
        $arg  = $this->extractGenericArg($type);

        if ($arg !== null) {
            $resolved_arg = $map[$arg] ?? $arg;
            if (isset($this->generic_structs[$base])) {
                $tp = $this->struct_type_params[$base];
                $inner_map = [$tp[0] => $resolved_arg];
                $result = $this->mangleStruct($base, $inner_map);
                $this->registerTypeUsage("$base<$resolved_arg>");
                return $ref . $result;
            }
            return $ref . "$base<$resolved_arg>";
        }

        if (isset($map[$type])) {
            return $ref . $map[$type];
        }

        return $ref . $type;
    }

    private function substituteBody(array $stmts, array $map): array {
        $result = [];
        foreach ($stmts as $stmt) {
            $result[] = $this->substituteStmt($stmt, $map);
        }
        return $result;
    }

    private function substituteStmt(mixed $stmt, array $map): mixed {
        if ($stmt instanceof LetNode) {
            $type_name = $stmt->type_name !== null ? $this->substituteType($stmt->type_name, $map) : null;
            $value     = $this->substituteExpr($stmt->value, $map);
            return new LetNode($stmt->name, $type_name, $value, $stmt->mutable, $stmt->line, $stmt->bindings);
        }
        if ($stmt instanceof AssignNode) {
            return new AssignNode($stmt->name, $this->substituteExpr($stmt->value, $map), $stmt->line);
        }
        if ($stmt instanceof CompoundAssignNode) {
            return new CompoundAssignNode(
                $this->substituteExpr($stmt->target, $map),
                $stmt->op,
                $this->substituteExpr($stmt->value, $map),
                $stmt->line
            );
        }
        if ($stmt instanceof FieldAssignNode) {
            return new FieldAssignNode(
                $this->substituteExpr($stmt->object, $map),
                $stmt->field_name,
                $this->substituteExpr($stmt->value, $map),
                $stmt->line
            );
        }
        if ($stmt instanceof DerefAssignNode) {
            return new DerefAssignNode(
                $this->substituteExpr($stmt->operand, $map),
                $this->substituteExpr($stmt->value, $map),
                $stmt->line
            );
        }
        if ($stmt instanceof ReturnNode) {
            return new ReturnNode(
                $stmt->value !== null ? $this->substituteExpr($stmt->value, $map) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof ExprStmtNode) {
            return new ExprStmtNode($this->substituteExpr($stmt->expr, $map), $stmt->line);
        }
        if ($stmt instanceof PrintlnNode) {
            $parts = [];
            foreach ($stmt->parts as $part) {
                $parts[] = is_string($part) ? $part : $this->substituteExpr($part, $map);
            }
            return new PrintlnNode($parts, $stmt->line);
        }
        if ($stmt instanceof IfNode) {
            return new IfNode(
                $this->substituteExpr($stmt->condition, $map),
                $this->substituteBody($stmt->then_body, $map),
                $stmt->else_body !== null ? $this->substituteBody($stmt->else_body, $map) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileNode) {
            return new WhileNode(
                $this->substituteExpr($stmt->condition, $map),
                $this->substituteBody($stmt->body, $map),
                $stmt->line
            );
        }
        if ($stmt instanceof IfLetNode) {
            $en = $stmt->enum_name !== null ? $this->substituteType($stmt->enum_name, $map) : null;
            return new IfLetNode(
                $this->substituteExpr($stmt->subject, $map),
                $en,
                $stmt->variant_name,
                $stmt->binding,
                $this->substituteBody($stmt->then_body, $map),
                $stmt->else_body !== null ? $this->substituteBody($stmt->else_body, $map) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileLetNode) {
            $en = $stmt->enum_name !== null ? $this->substituteType($stmt->enum_name, $map) : null;
            return new WhileLetNode(
                $this->substituteExpr($stmt->subject, $map),
                $en,
                $stmt->variant_name,
                $stmt->binding,
                $this->substituteBody($stmt->body, $map),
                $stmt->line
            );
        }
        if ($stmt instanceof LoopNode) {
            return new LoopNode($this->substituteBody($stmt->body, $map), $stmt->line);
        }
        if ($stmt instanceof MatchNode) {
            $arms = [];
            foreach ($stmt->arms as $arm) {
                $arms[] = new MatchArmNode(
                    $arm->is_wildcard, $arm->enum_name, $arm->variant_name,
                    $arm->binding, $this->substituteBody($arm->body, $map), $arm->line
                );
            }
            return new MatchNode($this->substituteExpr($stmt->subject, $map), $arms, $stmt->line);
        }
        return $stmt;
    }

    private function substituteExpr(mixed $expr, array $map): mixed {
        if ($expr instanceof StructLitNode) {
            $base = $this->stripGeneric($expr->struct_name);
            if (isset($this->generic_structs[$base])) {
                $tp = $this->struct_type_params[$base];
                $inner_map = [];
                foreach ($tp as $t) {
                    $inner_map[$t] = $map[$t] ?? $t;
                }
                $new_name = $this->mangleStruct($base, $inner_map);
            } else {
                $new_name = $this->substituteType($expr->struct_name, $map);
            }
            $fields = [];
            foreach ($expr->fields as $f) {
                $fields[] = ['name' => $f['name'], 'value' => $this->substituteExpr($f['value'], $map)];
            }
            return new StructLitNode($new_name, $fields, $expr->line);
        }
        if ($expr instanceof CallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->substituteExpr($a, $map);

            $parts = explode('::', $expr->name, 2);
            if (count($parts) === 2) {
                $struct_part  = $this->substituteType($parts[0], $map);
                return new CallNode("$struct_part::{$parts[1]}", $args, $expr->line);
            }
            return new CallNode($expr->name, $args, $expr->line);
        }
        if ($expr instanceof BinaryOpNode) {
            return new BinaryOpNode(
                $this->substituteExpr($expr->left, $map),
                $expr->op,
                $this->substituteExpr($expr->right, $map),
                $expr->line
            );
        }
        if ($expr instanceof UnaryOpNode) {
            return new UnaryOpNode($expr->op, $this->substituteExpr($expr->operand, $map), $expr->line);
        }
        if ($expr instanceof BorrowNode) {
            return new BorrowNode($this->substituteExpr($expr->operand, $map), $expr->mutable, $expr->line);
        }
        if ($expr instanceof DerefNode) {
            return new DerefNode($this->substituteExpr($expr->operand, $map), $expr->line);
        }
        if ($expr instanceof FieldAccessNode) {
            return new FieldAccessNode($this->substituteExpr($expr->object, $map), $expr->field_name, $expr->line);
        }
        if ($expr instanceof TupleLitNode) {
            $elements = [];
            foreach ($expr->elements as $e) $elements[] = $this->substituteExpr($e, $map);
            return new TupleLitNode($elements, $expr->line);
        }
        if ($expr instanceof TupleIndexNode) {
            return new TupleIndexNode($this->substituteExpr($expr->object, $map), $expr->index, $expr->line);
        }
        if ($expr instanceof CastNode) {
            return new CastNode(
                $this->substituteExpr($expr->expr, $map),
                $this->substituteType($expr->target_type, $map),
                $expr->line
            );
        }
        if ($expr instanceof MethodCallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->substituteExpr($a, $map);
            return new MethodCallNode($this->substituteExpr($expr->receiver, $map), $expr->method_name, $args, $expr->line);
        }
        if ($expr instanceof IfNode) {
            return $this->substituteStmt($expr, $map);
        }
        if ($expr instanceof IfLetNode) {
            return $this->substituteStmt($expr, $map);
        }
        if ($expr instanceof MatchNode) {
            return $this->substituteStmt($expr, $map);
        }
        return $expr;
    }

    private function rewriteBody(array $stmts): array {
        $result = [];
        foreach ($stmts as $stmt) {
            $result[] = $this->rewriteStmt($stmt);
        }
        return $result;
    }

    private function rewriteStmt(mixed $stmt): mixed {
        if ($stmt instanceof LetNode) {
            $type_name = $stmt->type_name !== null ? $this->rewriteTypeName($stmt->type_name) : null;
            $new_value = $this->rewriteExpr($stmt->value);
            $inferred = $stmt->type_name !== null
                ? preg_replace('/^&(mut )?/', '', $stmt->type_name)
                : $this->guessExprType($stmt->value);
            if (!empty($stmt->bindings)) {
                $element_types = $inferred !== null ? $this->tupleElementTypes($inferred) : null;
                if ($element_types !== null) {
                    foreach ($stmt->bindings as $i => $name) {
                        if (isset($element_types[$i])) $this->var_types[$name] = $element_types[$i];
                    }
                }
            } elseif ($inferred !== null) {
                $this->var_types[$stmt->name] = $inferred;
            }
            return new LetNode($stmt->name, $type_name, $new_value, $stmt->mutable, $stmt->line, $stmt->bindings);
        }
        if ($stmt instanceof AssignNode) {
            return new AssignNode($stmt->name, $this->rewriteExpr($stmt->value), $stmt->line);
        }
        if ($stmt instanceof CompoundAssignNode) {
            return new CompoundAssignNode(
                $this->rewriteExpr($stmt->target),
                $stmt->op,
                $this->rewriteExpr($stmt->value),
                $stmt->line
            );
        }
        if ($stmt instanceof FieldAssignNode) {
            return new FieldAssignNode(
                $this->rewriteExpr($stmt->object), $stmt->field_name,
                $this->rewriteExpr($stmt->value), $stmt->line
            );
        }
        if ($stmt instanceof DerefAssignNode) {
            return new DerefAssignNode(
                $this->rewriteExpr($stmt->operand),
                $this->rewriteExpr($stmt->value), $stmt->line
            );
        }
        if ($stmt instanceof ReturnNode) {
            return new ReturnNode(
                $stmt->value !== null ? $this->rewriteExpr($stmt->value) : null, $stmt->line
            );
        }
        if ($stmt instanceof ExprStmtNode) {
            return new ExprStmtNode($this->rewriteExpr($stmt->expr), $stmt->line);
        }
        if ($stmt instanceof PrintlnNode) {
            $parts = [];
            foreach ($stmt->parts as $part) {
                $parts[] = is_string($part) ? $part : $this->rewriteExpr($part);
            }
            return new PrintlnNode($parts, $stmt->line);
        }
        if ($stmt instanceof IfNode) {
            return new IfNode(
                $this->rewriteExpr($stmt->condition),
                $this->rewriteBody($stmt->then_body),
                $stmt->else_body !== null ? $this->rewriteBody($stmt->else_body) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileNode) {
            return new WhileNode(
                $this->rewriteExpr($stmt->condition),
                $this->rewriteBody($stmt->body), $stmt->line
            );
        }
        if ($stmt instanceof IfLetNode) {
            $en = $stmt->enum_name !== null ? $this->rewriteTypeName($stmt->enum_name) : null;
            return new IfLetNode(
                $this->rewriteExpr($stmt->subject),
                $en,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteBody($stmt->then_body),
                $stmt->else_body !== null ? $this->rewriteBody($stmt->else_body) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileLetNode) {
            $en = $stmt->enum_name !== null ? $this->rewriteTypeName($stmt->enum_name) : null;
            return new WhileLetNode(
                $this->rewriteExpr($stmt->subject),
                $en,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteBody($stmt->body),
                $stmt->line
            );
        }
        if ($stmt instanceof LoopNode) {
            return new LoopNode($this->rewriteBody($stmt->body), $stmt->line);
        }
        if ($stmt instanceof MatchNode) {
            $arms = [];
            foreach ($stmt->arms as $arm) {
                $arms[] = new MatchArmNode(
                    $arm->is_wildcard, $arm->enum_name, $arm->variant_name,
                    $arm->binding, $this->rewriteBody($arm->body), $arm->line
                );
            }
            return new MatchNode($this->rewriteExpr($stmt->subject), $arms, $stmt->line);
        }
        return $stmt;
    }

    private function rewriteExpr(mixed $expr): mixed {
        if ($expr instanceof CallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a);

            if (isset($this->generic_fns[$expr->name])) {
                $map = $this->inferTypeMap($this->generic_fns[$expr->name], $expr->args);
                if ($map !== null) {
                    $mangled = $this->mangleFn($expr->name, $map);
                    return new CallNode($mangled, $args, $expr->line);
                }
            }

            $parts = explode('::', $expr->name, 2);
            if (count($parts) === 2) {
                $struct_part = $parts[0];
                $method_name = $parts[1];
                if (isset($this->generic_structs[$struct_part])) {
                    $method_fn = $this->findImplMethod($struct_part, $method_name);
                    if ($method_fn !== null) {
                        $map = $this->inferImplTypeMap($struct_part, $method_fn, $expr->args);
                        if ($map !== null) {
                            $mangled = $this->mangleStruct($struct_part, $map);
                            return new CallNode("$mangled::$method_name", $args, $expr->line);
                        }
                    }
                }
                $rewritten = $this->rewriteTypeName($struct_part);
                return new CallNode("$rewritten::$method_name", $args, $expr->line);
            }

            return new CallNode($expr->name, $args, $expr->line);
        }
        if ($expr instanceof StructLitNode) {
            $fields = [];
            foreach ($expr->fields as $f) {
                $fields[] = ['name' => $f['name'], 'value' => $this->rewriteExpr($f['value'])];
            }
            $base = $this->stripGeneric($expr->struct_name);
            if (isset($this->generic_structs[$base])) {
                $arg = $this->extractGenericArg($expr->struct_name);
                if ($arg !== null) {
                    $tp  = $this->struct_type_params[$base];
                    $map = [$tp[0] => $arg];
                    $name = $this->mangleStruct($base, $map);
                } else {
                    $inferred = $this->inferStructType($base, $expr->fields);
                    if ($inferred !== null) {
                        $tp  = $this->struct_type_params[$base];
                        $map = [$tp[0] => $inferred];
                        $name = $this->mangleStruct($base, $map);
                    } else {
                        $name = $expr->struct_name;
                    }
                }
            } else {
                $name = $this->rewriteTypeName($expr->struct_name);
            }
            return new StructLitNode($name, $fields, $expr->line);
        }
        if ($expr instanceof MethodCallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a);
            return new MethodCallNode($this->rewriteExpr($expr->receiver), $expr->method_name, $args, $expr->line);
        }
        if ($expr instanceof BinaryOpNode) {
            return new BinaryOpNode(
                $this->rewriteExpr($expr->left), $expr->op,
                $this->rewriteExpr($expr->right), $expr->line
            );
        }
        if ($expr instanceof UnaryOpNode) {
            return new UnaryOpNode($expr->op, $this->rewriteExpr($expr->operand), $expr->line);
        }
        if ($expr instanceof BorrowNode) {
            return new BorrowNode($this->rewriteExpr($expr->operand), $expr->mutable, $expr->line);
        }
        if ($expr instanceof DerefNode) {
            return new DerefNode($this->rewriteExpr($expr->operand), $expr->line);
        }
        if ($expr instanceof FieldAccessNode) {
            return new FieldAccessNode($this->rewriteExpr($expr->object), $expr->field_name, $expr->line);
        }
        if ($expr instanceof TupleLitNode) {
            $elements = [];
            foreach ($expr->elements as $e) $elements[] = $this->rewriteExpr($e);
            return new TupleLitNode($elements, $expr->line);
        }
        if ($expr instanceof TupleIndexNode) {
            return new TupleIndexNode($this->rewriteExpr($expr->object), $expr->index, $expr->line);
        }
        if ($expr instanceof CastNode) {
            return new CastNode($this->rewriteExpr($expr->expr), $this->rewriteTypeName($expr->target_type), $expr->line);
        }
        if ($expr instanceof IndexNode) {
            return new IndexNode($this->rewriteExpr($expr->object), $this->rewriteExpr($expr->index), $expr->line);
        }
        if ($expr instanceof IfNode) {
            return $this->rewriteStmt($expr);
        }
        if ($expr instanceof IfLetNode) {
            return $this->rewriteStmt($expr);
        }
        if ($expr instanceof MatchNode) {
            return $this->rewriteStmt($expr);
        }
        return $expr;
    }

    private function rewriteFunctionSignature(FunctionNode $fn): FunctionNode {
        $new_params = [];
        foreach ($fn->params as $p) {
            $new_params[] = ['name' => $p['name'], 'type' => $this->rewriteTypeName($p['type'])];
        }
        $new_return = $fn->return_type !== null ? $this->rewriteTypeName($fn->return_type) : null;
        return new FunctionNode($fn->name, $new_params, $new_return, $fn->body, $fn->line, $fn->type_params, $fn->is_pub, $fn->module, $fn->type_bounds);
    }

    private function rewriteTypeName(string $type): string {
        $ref = '';
        if (str_starts_with($type, '&mut ')) { $ref = '&mut '; $type = substr($type, 5); }
        elseif (str_starts_with($type, '&'))  { $ref = '&';     $type = substr($type, 1); }

        if (str_starts_with($type, '*const ')) {
            return $ref . '*const ' . $this->rewriteTypeName(substr($type, 7));
        }
        if (str_starts_with($type, '*mut ')) {
            return $ref . '*mut ' . $this->rewriteTypeName(substr($type, 5));
        }

        $elements = $this->tupleElementTypes($type);
        if ($elements !== null) {
            $sub = [];
            foreach ($elements as $t) $sub[] = $this->rewriteTypeName($t);
            return $ref . '(' . implode(',', $sub) . ')';
        }

        $base = $this->stripGeneric($type);
        $arg  = $this->extractGenericArg($type);

        if ($arg !== null && isset($this->generic_structs[$base])) {
            $tp  = $this->struct_type_params[$base];
            $map = [$tp[0] => $arg];
            return $ref . $this->mangleStruct($base, $map);
        }
        return $ref . $type;
    }
}
