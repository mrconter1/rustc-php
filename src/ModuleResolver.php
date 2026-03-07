<?php

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/Lexer.php';
require_once __DIR__ . '/Parser.php';
require_once __DIR__ . '/Ast.php';

class ModuleResolver {
    private array $module_tree = [];
    private array $parsed_modules = [];

    public function resolve(string $root_file): ProgramNode {
        $root_file = realpath($root_file);
        if ($root_file === false) {
            throw new RuntimeException("File not found: $root_file");
        }

        $this->module_tree = $this->loadModuleTree($root_file, '');

        $this->parseAllModules($this->module_tree);

        $import_maps = $this->resolveImports($this->module_tree);

        return $this->flatten($this->module_tree, $import_maps);
    }

    private function readSource(string $file): string {
        $source = file_get_contents($file);
        if (str_starts_with($source, "\xFF\xFE")) {
            $source = substr($source, 2);
        }
        if (strlen($source) > 1 && $source[1] === "\x00") {
            $out = '';
            for ($i = 0; $i < strlen($source); $i += 2) {
                $out .= $source[$i];
            }
            $source = $out;
        }
        $source = ltrim($source, "\xEF\xBB\xBF");
        return $source;
    }

    private function loadModuleTree(string $file, string $prefix): array {
        $source = $this->readSource($file);
        $tokens = (new Lexer($source))->tokenize();

        $struct_names = [];
        $enum_names   = [];
        $pub_items    = [];
        $mod_decls    = [];

        $pos = 0;
        $count = count($tokens);
        while ($pos < $count && $tokens[$pos]->type !== Token::EOF) {
            $is_pub = false;
            if ($tokens[$pos]->type === Token::PUB) {
                $is_pub = true;
                $pos++;
            }
            if ($pos < $count && $tokens[$pos]->type === Token::STRUCT) {
                $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $name = $tokens[$pos]->value;
                    $struct_names[] = $name;
                    if ($is_pub) $pub_items[$name] = 'struct';
                    $pos++;
                }
                if ($pos < $count && $tokens[$pos]->type === Token::LT) {
                    while ($pos < $count && $tokens[$pos]->type !== Token::GT && $tokens[$pos]->type !== Token::EOF) $pos++;
                    if ($pos < $count && $tokens[$pos]->type === Token::GT) $pos++;
                }
                while ($pos < $count && $tokens[$pos]->type !== Token::RBRACE && $tokens[$pos]->type !== Token::EOF) $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::RBRACE) $pos++;
            } elseif ($pos < $count && $tokens[$pos]->type === Token::ENUM) {
                $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $name = $tokens[$pos]->value;
                    $enum_names[] = $name;
                    if ($is_pub) $pub_items[$name] = 'enum';
                    $pos++;
                }
                while ($pos < $count && $tokens[$pos]->type !== Token::RBRACE && $tokens[$pos]->type !== Token::EOF) $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::RBRACE) $pos++;
            } elseif ($pos < $count && $tokens[$pos]->type === Token::FN) {
                $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $name = $tokens[$pos]->value;
                    if ($is_pub) $pub_items[$name] = 'fn';
                    $pos++;
                }
                while ($pos < $count && $tokens[$pos]->type !== Token::RBRACE && $tokens[$pos]->type !== Token::EOF) {
                    if ($tokens[$pos]->type === Token::LBRACE) {
                        $pos++;
                        $depth = 1;
                        while ($pos < $count && $depth > 0) {
                            if ($tokens[$pos]->type === Token::LBRACE) $depth++;
                            elseif ($tokens[$pos]->type === Token::RBRACE) $depth--;
                            $pos++;
                        }
                        break;
                    }
                    $pos++;
                }
            } elseif ($pos < $count && $tokens[$pos]->type === Token::IMPL) {
                $pos++;
                $depth = 0;
                while ($pos < $count) {
                    if ($tokens[$pos]->type === Token::LBRACE) $depth++;
                    elseif ($tokens[$pos]->type === Token::RBRACE) {
                        $depth--;
                        if ($depth <= 0) { $pos++; break; }
                    }
                    $pos++;
                }
            } elseif ($pos < $count && $tokens[$pos]->type === Token::MOD) {
                $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $name = $tokens[$pos]->value;
                    $mod_decls[] = $name;
                    if ($is_pub) $pub_items[$name] = 'mod';
                    $pos++;
                }
                if ($pos < $count && $tokens[$pos]->type === Token::SEMICOLON) $pos++;
            } elseif ($pos < $count && $tokens[$pos]->type === Token::TRAIT) {
                $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $name = $tokens[$pos]->value;
                    if ($is_pub) $pub_items[$name] = 'trait';
                    $pos++;
                }
                $depth = 0;
                while ($pos < $count) {
                    if ($tokens[$pos]->type === Token::LBRACE) $depth++;
                    elseif ($tokens[$pos]->type === Token::RBRACE) {
                        $depth--;
                        if ($depth <= 0) { $pos++; break; }
                    }
                    $pos++;
                }
            } elseif ($pos < $count && $tokens[$pos]->type === Token::USE) {
                while ($pos < $count && $tokens[$pos]->type !== Token::SEMICOLON && $tokens[$pos]->type !== Token::EOF) $pos++;
                if ($pos < $count && $tokens[$pos]->type === Token::SEMICOLON) $pos++;
            } else {
                $pos++;
            }
        }

        $dir = dirname($file);
        $basename = pathinfo($file, PATHINFO_FILENAME);
        $submod_dir = ($basename !== 'mod' && $basename !== 'main' && $basename !== 'lib')
            ? $dir . DIRECTORY_SEPARATOR . $basename
            : $dir;
        $children = [];
        foreach ($mod_decls as $mod_name) {
            $mod_file = $this->resolveModFile($submod_dir, $mod_name, $prefix);
            $child_prefix = $prefix === '' ? $mod_name : $prefix . '__' . $mod_name;
            $children[$mod_name] = $this->loadModuleTree($mod_file, $child_prefix);
        }

        return [
            'file'         => $file,
            'prefix'       => $prefix,
            'tokens'       => $tokens,
            'struct_names' => $struct_names,
            'enum_names'   => $enum_names,
            'pub_items'    => $pub_items,
            'children'     => $children,
        ];
    }

    private function resolveModFile(string $dir, string $mod_name, string $parent_prefix): string {
        $file_path = $dir . DIRECTORY_SEPARATOR . $mod_name . '.rs';
        if (file_exists($file_path)) return realpath($file_path);

        $mod_dir_path = $dir . DIRECTORY_SEPARATOR . $mod_name . DIRECTORY_SEPARATOR . 'mod.rs';
        if (file_exists($mod_dir_path)) return realpath($mod_dir_path);

        throw new RuntimeException("Cannot find module '$mod_name': looked for '$file_path' and '$mod_dir_path'");
    }

    private function collectExportedNames(array $tree, array $path): ?array {
        if (empty($path)) {
            return $tree;
        }
        $segment = array_shift($path);
        if (!isset($tree['children'][$segment])) {
            return null;
        }
        return $this->collectExportedNames($tree['children'][$segment], $path);
    }

    private function resolveImports(array $tree): array {
        $import_maps = [];
        $this->resolveImportsForModule($tree, $import_maps);
        return $import_maps;
    }

    private function resolveImportsForModule(array $module, array &$import_maps): void {
        $prefix = $module['prefix'];

        $tokens = $module['tokens'];
        $pos = 0;
        $count = count($tokens);
        $imports = [];

        while ($pos < $count && $tokens[$pos]->type !== Token::EOF) {
            if ($tokens[$pos]->type === Token::USE) {
                $line = $tokens[$pos]->line;
                $pos++;
                $path = [];
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $path[] = $tokens[$pos]->value;
                    $pos++;
                }
                while ($pos < $count && $tokens[$pos]->type === Token::DCOLON) {
                    $pos++;
                    if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                        $path[] = $tokens[$pos]->value;
                        $pos++;
                    }
                }
                if ($pos < $count && $tokens[$pos]->type === Token::SEMICOLON) $pos++;

                if (count($path) >= 3 && $path[0] === 'crate') {
                    $mod_path = array_slice($path, 1, -1);
                    $item_name = end($path);

                    $current_tree = $this->module_tree;
                    for ($mi = 0; $mi < count($mod_path); $mi++) {
                        $seg = $mod_path[$mi];
                        if (!isset($current_tree['children'][$seg])) {
                            $mod_str = implode('::', array_slice($mod_path, 0, $mi + 1));
                            throw new RuntimeException("Module '$mod_str' not found on line $line");
                        }
                        if ($mi > 0 && !isset($current_tree['pub_items'][$seg])) {
                            throw new RuntimeException("Module '$seg' is not public on line $line");
                        }
                        $current_tree = $current_tree['children'][$seg];
                    }
                    $target_module = $current_tree;

                    if (!isset($target_module['pub_items'][$item_name])) {
                        $mod_str = implode('::', $mod_path);
                        throw new RuntimeException("Item '$item_name' is not public in module '$mod_str' on line $line");
                    }

                    $target_prefix = $target_module['prefix'];
                    $mangled = $target_prefix === '' ? $item_name : $target_prefix . '__' . $item_name;
                    $kind = $target_module['pub_items'][$item_name];
                    $imports[$item_name] = [
                        'mangled' => $mangled,
                        'kind'    => $kind,
                        'module'  => $target_prefix,
                    ];
                }
            } else {
                $pos++;
            }
        }

        $import_maps[$prefix] = $imports;

        foreach ($module['children'] as $child) {
            $this->resolveImportsForModule($child, $import_maps);
        }
    }

    private function parseAllModules(array &$tree): void {
        $extra_struct = [];
        $extra_enum   = [];

        $prefix = $tree['prefix'];
        $parent_imports = [];

        $this->collectImportedNames($tree, $extra_struct, $extra_enum);

        $tokens = $tree['tokens'];
        $parser = new Parser($tokens, $extra_struct, $extra_enum);
        $tree['ast'] = $parser->parse();

        foreach ($tree['children'] as &$child) {
            $this->parseAllModules($child);
        }
        unset($child);
    }

    private function collectImportedNames(array $tree, array &$extra_struct, array &$extra_enum): void {
        $tokens = $tree['tokens'];
        $pos = 0;
        $count = count($tokens);

        while ($pos < $count && $tokens[$pos]->type !== Token::EOF) {
            if ($tokens[$pos]->type === Token::USE) {
                $pos++;
                $path = [];
                if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                    $path[] = $tokens[$pos]->value;
                    $pos++;
                }
                while ($pos < $count && $tokens[$pos]->type === Token::DCOLON) {
                    $pos++;
                    if ($pos < $count && $tokens[$pos]->type === Token::IDENT) {
                        $path[] = $tokens[$pos]->value;
                        $pos++;
                    }
                }
                if ($pos < $count && $tokens[$pos]->type === Token::SEMICOLON) $pos++;

                if (count($path) >= 3 && $path[0] === 'crate') {
                    $mod_path = array_slice($path, 1, -1);
                    $item_name = end($path);
                    $target = $this->collectExportedNames($this->module_tree, $mod_path);
                    if ($target !== null && isset($target['pub_items'][$item_name])) {
                        $kind = $target['pub_items'][$item_name];
                        if ($kind === 'struct') $extra_struct[] = $item_name;
                        elseif ($kind === 'enum') $extra_enum[] = $item_name;
                    }
                }
            } else {
                $pos++;
            }
        }
    }

    private function flatten(array $tree, array $import_maps): ProgramNode {
        $all_functions = [];
        $all_structs   = [];
        $all_impls     = [];
        $all_enums     = [];
        $all_traits    = [];

        $this->flattenModule($tree, $import_maps, $all_functions, $all_structs, $all_impls, $all_enums, $all_traits);

        return new ProgramNode($all_functions, $all_structs, $all_impls, $all_enums, [], [], $all_traits);
    }

    private function flattenModule(
        array $tree,
        array $import_maps,
        array &$all_functions,
        array &$all_structs,
        array &$all_impls,
        array &$all_enums,
        array &$all_traits
    ): void {
        $prefix  = $tree['prefix'];
        $ast     = $tree['ast'];
        $imports = $import_maps[$prefix] ?? [];

        $struct_import_map = [];
        $enum_import_map   = [];
        $fn_import_map     = [];
        foreach ($imports as $local_name => $info) {
            if ($info['kind'] === 'struct') $struct_import_map[$local_name] = $info['mangled'];
            elseif ($info['kind'] === 'enum') $enum_import_map[$local_name] = $info['mangled'];
            elseif ($info['kind'] === 'fn') $fn_import_map[$local_name] = $info['mangled'];
        }

        $name_map = array_merge($struct_import_map, $enum_import_map, $fn_import_map);

        foreach ($ast->structs as $sd) {
            $mangled_name = $prefix === '' ? $sd->name : $prefix . '__' . $sd->name;
            $fields = [];
            foreach ($sd->fields as $f) {
                $fields[] = [
                    'name' => $f['name'],
                    'type' => $this->rewriteType($f['type'], $name_map),
                    'pub'  => $f['pub'] ?? false,
                ];
            }
            $struct_module = $prefix === '' ? null : $prefix;
            $all_structs[] = new StructDefNode($mangled_name, $fields, $sd->line, $sd->type_params, $sd->is_pub, $struct_module);
        }

        foreach ($ast->enums as $ed) {
            $mangled_name = $prefix === '' ? $ed->name : $prefix . '__' . $ed->name;
            $all_enums[] = new EnumDefNode($mangled_name, $ed->variants, $ed->line, $ed->is_pub);
        }

        foreach ($ast->functions as $fn) {
            $mangled_name = $prefix === '' ? $fn->name : $prefix . '__' . $fn->name;
            $module = $prefix === '' ? null : $prefix;
            $new_fn = $this->rewriteFunction($fn, $mangled_name, $name_map, $module, $prefix);
            $all_functions[] = $new_fn;
        }

        foreach ($ast->impls as $impl) {
            $impl_struct = isset($name_map[$impl->struct_name])
                ? $name_map[$impl->struct_name]
                : ($prefix === '' ? $impl->struct_name : $prefix . '__' . $impl->struct_name);

            $impl_trait = $impl->trait_name;
            if ($impl_trait !== null && isset($name_map[$impl_trait])) {
                $impl_trait = $name_map[$impl_trait];
            } elseif ($impl_trait !== null && $prefix !== '') {
                $impl_trait = $prefix . '__' . $impl_trait;
            }

            $new_fns = [];
            foreach ($impl->functions as $fn) {
                $module = $prefix === '' ? null : $prefix;
                $new_fn = $this->rewriteFunction($fn, $fn->name, $name_map, $module, $prefix);
                $new_fns[] = $new_fn;
            }
            $all_impls[] = new ImplNode($impl_struct, $new_fns, $impl->line, $impl->type_params, $impl_trait, $impl->type_bounds);
        }

        foreach ($ast->traits as $trait) {
            $mangled_name = $prefix === '' ? $trait->name : $prefix . '__' . $trait->name;
            $all_traits[] = new TraitNode($mangled_name, $trait->methods, $trait->line, $trait->is_pub);
        }

        foreach ($tree['children'] as $child) {
            $this->flattenModule($child, $import_maps, $all_functions, $all_structs, $all_impls, $all_enums, $all_traits);
        }
    }

    private function rewriteFunction(FunctionNode $fn, string $new_name, array $name_map, ?string $module, string $prefix): FunctionNode {
        $new_params = [];
        foreach ($fn->params as $p) {
            $new_params[] = ['name' => $p['name'], 'type' => $this->rewriteType($p['type'], $name_map)];
        }
        $new_return = $fn->return_type !== null ? $this->rewriteType($fn->return_type, $name_map) : null;
        $new_body   = $fn->body !== null ? $this->rewriteBody($fn->body, $name_map, $prefix) : null;
        return new FunctionNode($new_name, $new_params, $new_return, $new_body, $fn->line, $fn->type_params, $fn->is_pub, $module, $fn->type_bounds);
    }

    private function rewriteType(string $type, array $name_map): string {
        $ref = '';
        if (str_starts_with($type, '&mut ')) { $ref = '&mut '; $type = substr($type, 5); }
        elseif (str_starts_with($type, '&'))  { $ref = '&';     $type = substr($type, 1); }

        $base = $type;
        $generic_suffix = '';
        if (preg_match('/^([^<]+)(<.+>)$/', $type, $m)) {
            $base = $m[1];
            $generic_suffix = $m[2];
        }

        if (isset($name_map[$base])) {
            return $ref . $name_map[$base] . $generic_suffix;
        }
        return $ref . $type;
    }

    private function rewriteBody(array $stmts, array $name_map, string $prefix): array {
        $result = [];
        foreach ($stmts as $stmt) {
            $result[] = $this->rewriteStmt($stmt, $name_map, $prefix);
        }
        return $result;
    }

    private function rewriteStmt(mixed $stmt, array $name_map, string $prefix): mixed {
        if ($stmt instanceof LetNode) {
            $type_name = $stmt->type_name !== null ? $this->rewriteType($stmt->type_name, $name_map) : null;
            return new LetNode($stmt->name, $type_name, $this->rewriteExpr($stmt->value, $name_map, $prefix), $stmt->mutable, $stmt->line, $stmt->bindings);
        }
        if ($stmt instanceof AssignNode) {
            return new AssignNode($stmt->name, $this->rewriteExpr($stmt->value, $name_map, $prefix), $stmt->line);
        }
        if ($stmt instanceof FieldAssignNode) {
            return new FieldAssignNode(
                $this->rewriteExpr($stmt->object, $name_map, $prefix),
                $stmt->field_name,
                $this->rewriteExpr($stmt->value, $name_map, $prefix),
                $stmt->line
            );
        }
        if ($stmt instanceof DerefAssignNode) {
            return new DerefAssignNode(
                $this->rewriteExpr($stmt->operand, $name_map, $prefix),
                $this->rewriteExpr($stmt->value, $name_map, $prefix),
                $stmt->line
            );
        }
        if ($stmt instanceof ReturnNode) {
            return new ReturnNode(
                $stmt->value !== null ? $this->rewriteExpr($stmt->value, $name_map, $prefix) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof ExprStmtNode) {
            return new ExprStmtNode($this->rewriteExpr($stmt->expr, $name_map, $prefix), $stmt->line);
        }
        if ($stmt instanceof PrintlnNode) {
            $parts = [];
            foreach ($stmt->parts as $part) {
                $parts[] = is_string($part) ? $part : $this->rewriteExpr($part, $name_map, $prefix);
            }
            return new PrintlnNode($parts, $stmt->line);
        }
        if ($stmt instanceof IfNode) {
            return new IfNode(
                $this->rewriteExpr($stmt->condition, $name_map, $prefix),
                $this->rewriteBody($stmt->then_body, $name_map, $prefix),
                $stmt->else_body !== null ? $this->rewriteBody($stmt->else_body, $name_map, $prefix) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileNode) {
            return new WhileNode(
                $this->rewriteExpr($stmt->condition, $name_map, $prefix),
                $this->rewriteBody($stmt->body, $name_map, $prefix),
                $stmt->line
            );
        }
        if ($stmt instanceof IfLetNode) {
            $en = $stmt->enum_name;
            if ($en !== null && isset($name_map[$en])) $en = $name_map[$en];
            return new IfLetNode(
                $this->rewriteExpr($stmt->subject, $name_map, $prefix),
                $en,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteBody($stmt->then_body, $name_map, $prefix),
                $stmt->else_body !== null ? $this->rewriteBody($stmt->else_body, $name_map, $prefix) : null,
                $stmt->line
            );
        }
        if ($stmt instanceof WhileLetNode) {
            $en = $stmt->enum_name;
            if ($en !== null && isset($name_map[$en])) $en = $name_map[$en];
            return new WhileLetNode(
                $this->rewriteExpr($stmt->subject, $name_map, $prefix),
                $en,
                $stmt->variant_name,
                $stmt->binding,
                $this->rewriteBody($stmt->body, $name_map, $prefix),
                $stmt->line
            );
        }
        if ($stmt instanceof LoopNode) {
            return new LoopNode($this->rewriteBody($stmt->body, $name_map, $prefix), $stmt->line);
        }
        if ($stmt instanceof MatchNode) {
            $arms = [];
            foreach ($stmt->arms as $arm) {
                $en = $arm->enum_name;
                if ($en !== null && isset($name_map[$en])) $en = $name_map[$en];
                $arms[] = new MatchArmNode(
                    $arm->is_wildcard, $en, $arm->variant_name,
                    $arm->binding, $this->rewriteBody($arm->body, $name_map, $prefix), $arm->line
                );
            }
            return new MatchNode($this->rewriteExpr($stmt->subject, $name_map, $prefix), $arms, $stmt->line);
        }
        if ($stmt instanceof ForNode) {
            return new ForNode(
                $stmt->var_name,
                $this->rewriteExpr($stmt->iter_expr, $name_map, $prefix),
                $this->rewriteBody($stmt->body, $name_map, $prefix),
                $stmt->line
            );
        }
        return $stmt;
    }

    private function rewriteExpr(mixed $expr, array $name_map, string $prefix): mixed {
        if ($expr instanceof CallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a, $name_map, $prefix);

            $call_name = $expr->name;
            $parts = explode('::', $call_name, 2);
            if (count($parts) === 2) {
                $struct_part = $parts[0];
                $method = $parts[1];
                if (isset($name_map[$struct_part])) {
                    $call_name = $name_map[$struct_part] . '::' . $method;
                } elseif ($prefix !== '' && !in_array($struct_part, ['String'])) {
                    $is_local = false;
                    foreach ($this->findModuleByPrefix($prefix)['struct_names'] ?? [] as $sn) {
                        if ($sn === $struct_part) { $is_local = true; break; }
                    }
                    if ($is_local) {
                        $call_name = $prefix . '__' . $struct_part . '::' . $method;
                    }
                }
            } elseif (isset($name_map[$call_name])) {
                $call_name = $name_map[$call_name];
            } elseif ($prefix !== '' && $call_name !== 'exit') {
                $is_local_fn = false;
                $mod = $this->findModuleByPrefix($prefix);
                if ($mod) {
                    $ast = $mod['ast'] ?? null;
                    if ($ast) {
                        foreach ($ast->functions as $f) {
                            if ($f->name === $call_name) { $is_local_fn = true; break; }
                        }
                    }
                }
                if ($is_local_fn) {
                    $call_name = $prefix . '__' . $call_name;
                }
            }
            return new CallNode($call_name, $args, $expr->line);
        }
        if ($expr instanceof StructLitNode) {
            $fields = [];
            foreach ($expr->fields as $f) {
                $fields[] = ['name' => $f['name'], 'value' => $this->rewriteExpr($f['value'], $name_map, $prefix)];
            }
            $name = $expr->struct_name;
            if (isset($name_map[$name])) {
                $name = $name_map[$name];
            } elseif ($prefix !== '') {
                $mod = $this->findModuleByPrefix($prefix);
                if ($mod && in_array($name, $mod['struct_names'])) {
                    $name = $prefix . '__' . $name;
                }
            }
            return new StructLitNode($name, $fields, $expr->line);
        }
        if ($expr instanceof EnumVariantNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a, $name_map, $prefix);
            $en = $expr->enum_name;
            if (isset($name_map[$en])) $en = $name_map[$en];
            elseif ($prefix !== '') {
                $mod = $this->findModuleByPrefix($prefix);
                if ($mod && in_array($en, $mod['enum_names'])) $en = $prefix . '__' . $en;
            }
            return new EnumVariantNode($en, $expr->variant_name, $args, $expr->line);
        }
        if ($expr instanceof MethodCallNode) {
            $args = [];
            foreach ($expr->args as $a) $args[] = $this->rewriteExpr($a, $name_map, $prefix);
            return new MethodCallNode($this->rewriteExpr($expr->receiver, $name_map, $prefix), $expr->method_name, $args, $expr->line);
        }
        if ($expr instanceof BinaryOpNode) {
            return new BinaryOpNode(
                $this->rewriteExpr($expr->left, $name_map, $prefix),
                $expr->op,
                $this->rewriteExpr($expr->right, $name_map, $prefix),
                $expr->line
            );
        }
        if ($expr instanceof UnaryOpNode) {
            return new UnaryOpNode($expr->op, $this->rewriteExpr($expr->operand, $name_map, $prefix), $expr->line);
        }
        if ($expr instanceof BorrowNode) {
            return new BorrowNode($this->rewriteExpr($expr->operand, $name_map, $prefix), $expr->mutable, $expr->line);
        }
        if ($expr instanceof DerefNode) {
            return new DerefNode($this->rewriteExpr($expr->operand, $name_map, $prefix), $expr->line);
        }
        if ($expr instanceof CastNode) {
            return new CastNode($this->rewriteExpr($expr->expr, $name_map, $prefix), $expr->target_type, $expr->line);
        }
        if ($expr instanceof FieldAccessNode) {
            return new FieldAccessNode($this->rewriteExpr($expr->object, $name_map, $prefix), $expr->field_name, $expr->line);
        }
        if ($expr instanceof IndexNode) {
            return new IndexNode($this->rewriteExpr($expr->object, $name_map, $prefix), $this->rewriteExpr($expr->index, $name_map, $prefix), $expr->line);
        }
        if ($expr instanceof IfNode) {
            return $this->rewriteStmt($expr, $name_map, $prefix);
        }
        if ($expr instanceof IfLetNode) {
            return $this->rewriteStmt($expr, $name_map, $prefix);
        }
        if ($expr instanceof MatchNode) {
            return $this->rewriteStmt($expr, $name_map, $prefix);
        }
        if ($expr instanceof RangeNode) {
            return new RangeNode(
                $this->rewriteExpr($expr->start, $name_map, $prefix),
                $this->rewriteExpr($expr->end, $name_map, $prefix),
                $expr->line
            );
        }
        if ($expr instanceof ClosureNode) {
            $body = $this->rewriteBody($expr->body, $name_map, $prefix);
            return new ClosureNode($expr->params, $body, $expr->line);
        }
        return $expr;
    }

    private function findModuleByPrefix(string $prefix): ?array {
        return $this->findModuleInTree($this->module_tree, $prefix);
    }

    private function findModuleInTree(array $tree, string $prefix): ?array {
        if ($tree['prefix'] === $prefix) return $tree;
        foreach ($tree['children'] as $child) {
            $result = $this->findModuleInTree($child, $prefix);
            if ($result !== null) return $result;
        }
        return null;
    }
}
