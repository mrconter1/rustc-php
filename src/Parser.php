<?php

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/Lexer.php';
require_once __DIR__ . '/Ast.php';

class Parser {
    private array $tokens;
    private int   $pos = 0;
    private array $struct_names = [];
    private array $enum_names   = [];
    private array $last_type_bounds = [];

    public function __construct(array $tokens, array $extra_struct_names = [], array $extra_enum_names = []) {
        $this->tokens = $tokens;
        $this->struct_names = $extra_struct_names;
        $this->enum_names   = $extra_enum_names;
    }

    public function parse(): ProgramNode {
        $saved = $this->pos;
        while (!$this->check(Token::EOF)) {
            $this->skipAttributes();
            if ($this->check(Token::PUB)) $this->pos++;
            if ($this->check(Token::MOD) || $this->check(Token::USE)) {
                while (!$this->check(Token::SEMICOLON) && !$this->check(Token::EOF)) $this->pos++;
                if ($this->check(Token::SEMICOLON)) $this->pos++;
            } elseif ($this->check(Token::TRAIT)) {
                $this->pos++;
                if ($this->check(Token::IDENT)) $this->pos++;
                while (!$this->check(Token::RBRACE) && !$this->check(Token::EOF)) {
                    if ($this->check(Token::LBRACE)) {
                        $this->pos++;
                        $depth = 1;
                        while ($this->pos < count($this->tokens) && $depth > 0) {
                            if ($this->check(Token::LBRACE)) $depth++;
                            elseif ($this->check(Token::RBRACE)) $depth--;
                            if ($depth > 0) $this->pos++;
                        }
                        break;
                    }
                    $this->pos++;
                }
                if ($this->check(Token::RBRACE)) $this->pos++;
            } elseif ($this->check(Token::STRUCT)) {
                $this->pos++;
                $this->struct_names[] = $this->expect(Token::IDENT)->value;
                if ($this->check(Token::LT)) {
                    while (!$this->check(Token::GT) && !$this->check(Token::EOF)) $this->pos++;
                    if ($this->check(Token::GT)) $this->pos++;
                }
                while (!$this->check(Token::RBRACE) && !$this->check(Token::EOF)) {
                    $this->pos++;
                }
                if ($this->check(Token::RBRACE)) $this->pos++;
            } elseif ($this->check(Token::ENUM)) {
                $this->pos++;
                $this->enum_names[] = $this->expect(Token::IDENT)->value;
                while (!$this->check(Token::RBRACE) && !$this->check(Token::EOF)) {
                    $this->pos++;
                }
                if ($this->check(Token::RBRACE)) $this->pos++;
            } else {
                $this->pos++;
            }
        }
        $this->pos = $saved;

        $functions = [];
        $structs   = [];
        $impls     = [];
        $enums     = [];
        $mod_decls = [];
        $uses      = [];
        $traits    = [];
        while (!$this->check(Token::EOF)) {
            $this->skipAttributes();
            $is_pub = false;
            if ($this->check(Token::PUB)) {
                $is_pub = true;
                $this->pos++;
            }
            if ($this->check(Token::MOD)) {
                $mod_decls[] = $this->parseModDecl();
            } elseif ($this->check(Token::USE)) {
                $uses[] = $this->parseUse();
            } elseif ($this->check(Token::TRAIT)) {
                $traits[] = $this->parseTrait($is_pub);
            } elseif ($this->check(Token::STRUCT)) {
                $structs[] = $this->parseStruct($is_pub);
            } elseif ($this->check(Token::ENUM)) {
                $enums[] = $this->parseEnum($is_pub);
            } elseif ($this->check(Token::IMPL)) {
                $impls[] = $this->parseImpl();
            } else {
                $functions[] = $this->parseFunction($is_pub);
            }
        }
        return new ProgramNode($functions, $structs, $impls, $enums, $mod_decls, $uses, $traits);
    }

    private function parseModDecl(): ModDeclNode {
        $line = $this->expect(Token::MOD)->line;
        $name = $this->expect(Token::IDENT)->value;
        $this->expect(Token::SEMICOLON);
        return new ModDeclNode($name, $line);
    }

    private function parseUse(): UseNode {
        $line = $this->expect(Token::USE)->line;
        $path = [];
        $path[] = $this->expect(Token::IDENT)->value;
        while ($this->check(Token::DCOLON)) {
            $this->pos++;
            $path[] = $this->expect(Token::IDENT)->value;
        }
        $this->expect(Token::SEMICOLON);
        return new UseNode($path, $line);
    }

    private function parseEnum(bool $is_pub = false): EnumDefNode {
        $line = $this->expect(Token::ENUM)->line;
        $name = $this->expect(Token::IDENT)->value;
        $this->expect(Token::LBRACE);
        $variants = [];
        while (!$this->check(Token::RBRACE)) {
            $vname  = $this->expect(Token::IDENT)->value;
            $fields = [];
            if ($this->check(Token::LPAREN)) {
                $this->pos++;
                while (!$this->check(Token::RPAREN)) {
                    $ref = '';
                    if ($this->check(Token::AMP)) {
                        $ref = '&';
                        $this->pos++;
                        if ($this->check(Token::MUT)) { $ref = '&mut '; $this->pos++; }
                    }
                    $fields[] = $ref . $this->expect(Token::IDENT)->value;
                    if ($this->check(Token::COMMA)) $this->pos++;
                }
                $this->expect(Token::RPAREN);
            }
            $variants[] = ['name' => $vname, 'fields' => $fields];
            if ($this->check(Token::COMMA)) $this->pos++;
        }
        $this->expect(Token::RBRACE);
        return new EnumDefNode($name, $variants, $line, $is_pub);
    }

    private function parseMatch(): MatchNode {
        $line    = $this->expect(Token::MATCH)->line;
        $subject = $this->parseExpr();
        $this->expect(Token::LBRACE);
        $arms = [];
        while (!$this->check(Token::RBRACE)) {
            $pat_token   = $this->current();
            $is_wildcard = false;
            $enum_name   = null;
            $variant_name = null;
            $binding     = null;

            if ($pat_token->type === Token::IDENT && $pat_token->value === '_') {
                $this->pos++;
                $is_wildcard = true;
            } else {
                $enum_name    = $this->expect(Token::IDENT)->value;
                $this->expect(Token::DCOLON);
                $variant_name = $this->expect(Token::IDENT)->value;
                if ($this->check(Token::LPAREN)) {
                    $this->pos++;
                    $binding = $this->expect(Token::IDENT)->value;
                    $this->expect(Token::RPAREN);
                }
            }

            $this->expect(Token::FAT_ARROW);

            if ($this->check(Token::LBRACE)) {
                $body = $this->parseBlock();
            } else {
                $expr = $this->parseExpr();
                $body = [new ReturnNode($expr, $expr->line)];
                if ($this->check(Token::COMMA)) $this->pos++;
            }

            $arms[] = new MatchArmNode($is_wildcard, $enum_name, $variant_name, $binding, $body, $pat_token->line);
            if (!$is_wildcard && $this->check(Token::COMMA)) $this->pos++;
        }
        $this->expect(Token::RBRACE);
        return new MatchNode($subject, $arms, $line);
    }

    private function parseTrait(bool $is_pub = false): TraitNode {
        $line = $this->expect(Token::TRAIT)->line;
        $name = $this->expect(Token::IDENT)->value;
        $this->expect(Token::LBRACE);
        $methods = [];
        while (!$this->check(Token::RBRACE)) {
            $this->skipAttributes();
            $this->expect(Token::FN);
            $fn_name = $this->expect(Token::IDENT)->value;
            $fn_line = $this->current()->line;
            $this->expect(Token::LPAREN);
            $params = [];
            while (!$this->check(Token::RPAREN)) {
                if ($this->check(Token::SELF)) {
                    $this->pos++;
                    $params[] = ['name' => 'self', 'type' => 'self'];
                } elseif ($this->check(Token::AMP)) {
                    $this->pos++;
                    $mut = false;
                    if ($this->check(Token::MUT)) { $mut = true; $this->pos++; }
                    $this->expect(Token::SELF);
                    $params[] = ['name' => 'self', 'type' => ($mut ? '&mut self' : '&self')];
                } else {
                    $pname = $this->expect(Token::IDENT)->value;
                    $this->expect(Token::COLON);
                    $ptype = $this->parseType();
                    $params[] = ['name' => $pname, 'type' => $ptype];
                }
                if ($this->check(Token::COMMA)) $this->pos++;
            }
            $this->expect(Token::RPAREN);
            $return_type = null;
            if ($this->check(Token::ARROW)) {
                $this->pos++;
                $return_type = $this->parseType();
            }
            if ($this->check(Token::SEMICOLON)) {
                $this->pos++;
                $methods[] = new FunctionNode($fn_name, $params, $return_type, null, $fn_line);
            } else {
                $body = $this->parseBlock();
                $methods[] = new FunctionNode($fn_name, $params, $return_type, $body, $fn_line);
            }
        }
        $this->expect(Token::RBRACE);
        return new TraitNode($name, $methods, $line, $is_pub);
    }

    private function parseImpl(): ImplNode {
        $line = $this->expect(Token::IMPL)->line;
        $type_params = $this->parseTypeParams();
        $type_bounds = $this->last_type_bounds;
        $first_name = $this->expect(Token::IDENT)->value;
        $trait_name = null;
        $struct_name = $first_name;
        if ($this->check(Token::FOR)) {
            $this->pos++;
            $trait_name = $first_name;
            $struct_name = $this->expect(Token::IDENT)->value;
        }
        if ($this->check(Token::LT)) {
            $this->pos++;
            $inner = $this->expect(Token::IDENT)->value;
            $this->expect(Token::GT);
            $struct_name = "$struct_name<$inner>";
        }
        $this->expect(Token::LBRACE);
        $functions = [];
        while (!$this->check(Token::RBRACE)) {
            $this->skipAttributes();
            $fn_pub = false;
            if ($this->check(Token::PUB)) {
                $fn_pub = true;
                $this->pos++;
            }
            $functions[] = $this->parseFunction($fn_pub);
        }
        $this->expect(Token::RBRACE);
        return new ImplNode($struct_name, $functions, $line, $type_params, $trait_name, $type_bounds);
    }

    private function parseStruct(bool $is_pub = false): StructDefNode {
        $line = $this->expect(Token::STRUCT)->line;
        $name = $this->expect(Token::IDENT)->value;
        $type_params = $this->parseTypeParams();
        $this->expect(Token::LBRACE);
        $fields = [];
        while (!$this->check(Token::RBRACE)) {
            $field_pub = false;
            if ($this->check(Token::PUB)) {
                $field_pub = true;
                $this->pos++;
            }
            $fname = $this->expect(Token::IDENT)->value;
            $this->expect(Token::COLON);
            $ftype = $this->parseType();
            $fields[] = ['name' => $fname, 'type' => $ftype, 'pub' => $field_pub];
            if ($this->check(Token::COMMA)) {
                $this->pos++;
            }
        }
        $this->expect(Token::RBRACE);
        return new StructDefNode($name, $fields, $line, $type_params, $is_pub);
    }

    private function parseFunction(bool $is_pub = false): FunctionNode {
        $this->expect(Token::FN);
        $name = $this->expect(Token::IDENT)->value;
        $type_params = $this->parseTypeParams();
        $type_bounds = $this->last_type_bounds;
        $line = $this->current()->line;

        $this->expect(Token::LPAREN);
        $params = [];
        while (!$this->check(Token::RPAREN)) {
            if ($this->check(Token::SELF)) {
                $this->pos++;
                $params[] = ['name' => 'self', 'type' => 'self'];
            } elseif ($this->check(Token::AMP)) {
                $this->pos++;
                $mut = false;
                if ($this->check(Token::MUT)) {
                    $mut = true;
                    $this->pos++;
                }
                $this->expect(Token::SELF);
                $params[] = ['name' => 'self', 'type' => ($mut ? '&mut self' : '&self')];
            } else {
                $pname = $this->expect(Token::IDENT)->value;
                $this->expect(Token::COLON);
                $ptype = $this->parseType();
                $params[] = ['name' => $pname, 'type' => $ptype];
            }
            if ($this->check(Token::COMMA)) {
                $this->pos++;
            }
        }
        $this->expect(Token::RPAREN);

        $return_type = null;
        if ($this->check(Token::ARROW)) {
            $this->pos++;
            $return_type = $this->parseType();
        }

        $body = $this->parseBlock();
        return new FunctionNode($name, $params, $return_type, $body, $line, $type_params, $is_pub, null, $type_bounds);
    }

    private function parseBlock(): array {
        $this->expect(Token::LBRACE);
        $stmts = [];
        while (!$this->check(Token::RBRACE) && !$this->check(Token::EOF)) {
            $stmts[] = $this->parseStatement();
        }
        $this->expect(Token::RBRACE);
        return $stmts;
    }

    private function parseStatement(): mixed {
        if ($this->check(Token::LET)) {
            return $this->parseLet();
        }
        if ($this->check(Token::IF)) {
            return $this->parseIf();
        }
        if ($this->check(Token::WHILE)) {
            return $this->parseWhile();
        }
        if ($this->check(Token::LOOP)) {
            $line = $this->expect(Token::LOOP)->line;
            $body = $this->parseBlock();
            return new LoopNode($body, $line);
        }
        if ($this->check(Token::FOR)) {
            $line = $this->expect(Token::FOR)->line;
            $var_name = $this->expect(Token::IDENT)->value;
            $this->expect(Token::IN);
            $iter_expr = $this->parseExpr();
            $body = $this->parseBlock();
            return new ForNode($var_name, $iter_expr, $body, $line);
        }
        if ($this->check(Token::BREAK)) {
            $line = $this->expect(Token::BREAK)->line;
            $this->expect(Token::SEMICOLON);
            return new BreakNode($line);
        }
        if ($this->check(Token::CONTINUE)) {
            $line = $this->expect(Token::CONTINUE)->line;
            $this->expect(Token::SEMICOLON);
            return new ContinueNode($line);
        }
        if ($this->check(Token::RETURN)) {
            $line = $this->expect(Token::RETURN)->line;
            if ($this->check(Token::SEMICOLON)) {
                $this->pos++;
                return new ReturnNode(null, $line);
            }
            $value = $this->parseExpr();
            $this->expect(Token::SEMICOLON);
            return new ReturnNode($value, $line);
        }
        if ($this->check(Token::MACRO)) {
            return $this->parseMacroCall();
        }
        if ($this->check(Token::MATCH)) {
            $node = $this->parseMatch();
            if ($this->check(Token::SEMICOLON)) $this->pos++;
            return $node;
        }
        $expr = $this->parseExpr();

        if ($expr instanceof IdentNode && $this->check(Token::EQ)) {
            $line = $this->current()->line;
            $this->pos++;
            $value = $this->parseExpr();
            $this->expect(Token::SEMICOLON);
            return new AssignNode($expr->name, $value, $line);
        }

        if ($expr instanceof DerefNode && $this->check(Token::EQ)) {
            $line = $this->current()->line;
            $this->pos++;
            $value = $this->parseExpr();
            $this->expect(Token::SEMICOLON);
            return new DerefAssignNode($expr->operand, $value, $line);
        }

        if ($expr instanceof FieldAccessNode && $this->check(Token::EQ)) {
            $line = $this->current()->line;
            $this->pos++;
            $value = $this->parseExpr();
            $this->expect(Token::SEMICOLON);
            return new FieldAssignNode($expr->object, $expr->field_name, $value, $line);
        }

        if ($this->check(Token::RBRACE)) {
            return new ReturnNode($expr, $expr->line);
        }

        $this->expect(Token::SEMICOLON);
        return new ExprStmtNode($expr, $expr->line);
    }

    private function parseMacroCall(): mixed {
        $token = $this->expect(Token::MACRO);
        if ($token->value !== 'println') {
            throw new RuntimeException("Unknown macro '{$token->value}!' on line {$token->line}");
        }
        return $this->parsePrintln($token->line);
    }

    private function parsePrintln(int $line): PrintlnNode {
        $this->expect(Token::LPAREN);

        if ($this->check(Token::RPAREN)) {
            $this->expect(Token::RPAREN);
            $this->expect(Token::SEMICOLON);
            return new PrintlnNode(["\n"], $line);
        }

        $format = $this->expect(Token::STR_LIT)->value;

        $args = [];
        while ($this->check(Token::COMMA)) {
            $this->pos++;
            $args[] = $this->parseExpr();
        }

        $this->expect(Token::RPAREN);
        $this->expect(Token::SEMICOLON);

        $format_parts = explode('{}', $format);
        $expected_args = count($format_parts) - 1;
        if (count($args) !== $expected_args) {
            throw new RuntimeException(
                "println! expected $expected_args arguments, got " . count($args) . " on line $line"
            );
        }

        $parts = [];
        for ($i = 0; $i < count($format_parts); $i++) {
            if ($format_parts[$i] !== '') {
                $parts[] = $format_parts[$i];
            }
            if ($i < $expected_args) {
                $parts[] = $args[$i];
            }
        }

        if (count($parts) > 0 && is_string($parts[count($parts) - 1])) {
            $parts[count($parts) - 1] .= "\n";
        } else {
            $parts[] = "\n";
        }

        return new PrintlnNode($parts, $line);
    }

    private function parseLet(): LetNode {
        $line = $this->expect(Token::LET)->line;

        $mutable = false;
        if ($this->check(Token::MUT)) {
            $mutable = true;
            $this->pos++;
        }

        $name = $this->expect(Token::IDENT)->value;

        $type_name = null;
        if ($this->check(Token::COLON)) {
            $this->expect(Token::COLON);
            $type_name = $this->parseType();
        }

        $this->expect(Token::EQ);
        $value = $this->parseExpr();
        $this->expect(Token::SEMICOLON);

        return new LetNode($name, $type_name, $value, $mutable, $line);
    }

    private function parseIf(): IfNode {
        $line = $this->expect(Token::IF)->line;
        $condition = $this->parseExpr();
        $then_body = $this->parseBlock();

        $else_body = null;
        if ($this->check(Token::ELSE)) {
            $this->pos++;
            if ($this->check(Token::IF)) {
                $else_body = [$this->parseIf()];
            } else {
                $else_body = $this->parseBlock();
            }
        }

        return new IfNode($condition, $then_body, $else_body, $line);
    }

    private function parseWhile(): WhileNode {
        $line = $this->expect(Token::WHILE)->line;
        $condition = $this->parseExpr();
        $body = $this->parseBlock();
        return new WhileNode($condition, $body, $line);
    }

    private function parseStructLiteral(string $name, int $line): StructLitNode {
        $this->expect(Token::LBRACE);
        $fields = [];
        while (!$this->check(Token::RBRACE)) {
            $fname = $this->expect(Token::IDENT)->value;
            $this->expect(Token::COLON);
            $value = $this->parseExpr();
            $fields[] = ['name' => $fname, 'value' => $value];
            if ($this->check(Token::COMMA)) {
                $this->pos++;
            }
        }
        $this->expect(Token::RBRACE);
        return new StructLitNode($name, $fields, $line);
    }

    // --- expression parsing with precedence ---

    private function parseExpr(): mixed {
        return $this->parseRange();
    }

    private function parseRange(): mixed {
        $left = $this->parseLogicalOr();
        if ($this->check(Token::DOTDOT)) {
            $line = $this->current()->line;
            $this->pos++;
            $right = $this->parseLogicalOr();
            return new RangeNode($left, $right, $line);
        }
        return $left;
    }

    private function parseLogicalOr(): mixed {
        $left = $this->parseLogicalAnd();
        while ($this->check(Token::OR)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseLogicalAnd();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseLogicalAnd(): mixed {
        $left = $this->parseComparison();
        while ($this->check(Token::AND)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseComparison();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseComparison(): mixed {
        $left = $this->parseAddSub();
        while ($this->check(Token::EQEQ) || $this->check(Token::NEQ)
            || $this->check(Token::LT)   || $this->check(Token::GT)
            || $this->check(Token::LTE)  || $this->check(Token::GTE)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseAddSub();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseAddSub(): mixed {
        $left = $this->parseMulDiv();
        while ($this->check(Token::PLUS) || $this->check(Token::MINUS)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseMulDiv();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseMulDiv(): mixed {
        $left = $this->parseUnary();
        while ($this->check(Token::STAR) || $this->check(Token::SLASH) || $this->check(Token::PERCENT)) {
            $op    = $this->current()->value;
            $line  = $this->current()->line;
            $this->pos++;
            $right = $this->parseUnary();
            $left  = new BinaryOpNode($left, $op, $right, $line);
        }
        return $left;
    }

    private function parseUnary(): mixed {
        if ($this->check(Token::MINUS)) {
            $line = $this->current()->line;
            $this->pos++;
            $operand = $this->parseUnary();
            return new UnaryOpNode('-', $operand, $line);
        }
        if ($this->check(Token::BANG)) {
            $line = $this->current()->line;
            $this->pos++;
            $operand = $this->parseUnary();
            return new UnaryOpNode('!', $operand, $line);
        }
        if ($this->check(Token::STAR)) {
            $line = $this->current()->line;
            $this->pos++;
            $operand = $this->parseUnary();
            return new DerefNode($operand, $line);
        }
        if ($this->check(Token::AMP)) {
            $line = $this->current()->line;
            $this->pos++;
            $mutable = false;
            if ($this->check(Token::MUT)) {
                $mutable = true;
                $this->pos++;
            }
            $operand = $this->parseUnary();
            return new BorrowNode($operand, $mutable, $line);
        }
        return $this->parsePrimary();
    }

    private function parsePrimary(): mixed {
        $token = $this->current();

        if ($token->type === Token::INT_LIT) {
            $this->pos++;
            return new IntLitNode($token->value, $token->line);
        }

        if ($token->type === Token::TRUE) {
            $this->pos++;
            return new BoolLitNode(true, $token->line);
        }

        if ($token->type === Token::FALSE) {
            $this->pos++;
            return new BoolLitNode(false, $token->line);
        }

        if ($token->type === Token::STR_LIT) {
            $this->pos++;
            $expr = new StrSliceNode($token->value, $token->line);
            while ($this->check(Token::LBRACKET)) {
                $idx_line = $this->current()->line;
                $this->pos++;
                $idx = $this->parseExpr();
                $this->expect(Token::RBRACKET);
                $expr = new IndexNode($expr, $idx, $idx_line);
            }
            return $expr;
        }

        if ($token->type === Token::MATCH) {
            return $this->parseMatch();
        }

        if ($token->type === Token::IDENT || $token->type === Token::SELF) {
            $this->pos++;
            if ($this->check(Token::DCOLON) && in_array($token->value, $this->enum_names)) {
                $this->expect(Token::DCOLON);
                $variant_name = $this->expect(Token::IDENT)->value;
                $args = [];
                if ($this->check(Token::LPAREN)) {
                    $this->pos++;
                    while (!$this->check(Token::RPAREN)) {
                        $args[] = $this->parseExpr();
                        if ($this->check(Token::COMMA)) $this->pos++;
                    }
                    $this->expect(Token::RPAREN);
                }
                return new EnumVariantNode($token->value, $variant_name, $args, $token->line);
            }
            if ($this->check(Token::DCOLON)) {
                $this->expect(Token::DCOLON);
                $method = $this->expect(Token::IDENT)->value;
                if ($token->value === 'String' && $method === 'from') {
                    $this->expect(Token::LPAREN);
                    $str = $this->expect(Token::STR_LIT)->value;
                    $this->expect(Token::RPAREN);
                    return new StringFromNode($str, $token->line);
                }

                $this->expect(Token::LPAREN);
                $args = [];
                while (!$this->check(Token::RPAREN)) {
                    $args[] = $this->parseExpr();
                    if ($this->check(Token::COMMA)) {
                        $this->pos++;
                    }
                }
                $this->expect(Token::RPAREN);
                return new CallNode("{$token->value}::$method", $args, $token->line);
            }

            $expr = null;
            if ($this->check(Token::LBRACE) && in_array($token->value, $this->struct_names)) {
                $expr = $this->parseStructLiteral($token->value, $token->line);
            } elseif ($this->check(Token::LPAREN)) {
                $this->expect(Token::LPAREN);
                $args = [];
                while (!$this->check(Token::RPAREN)) {
                    $args[] = $this->parseExpr();
                    if ($this->check(Token::COMMA)) {
                        $this->pos++;
                    }
                }
                $this->expect(Token::RPAREN);
                $expr = new CallNode($token->value, $args, $token->line);
            } else {
                $expr = new IdentNode($token->value, $token->line);
            }

            while ($this->check(Token::DOT)) {
                $this->pos++;
                $name = $this->expect(Token::IDENT)->value;
                if ($this->check(Token::LPAREN)) {
                    $this->pos++;
                    $args = [];
                    while (!$this->check(Token::RPAREN)) {
                        $args[] = $this->parseExpr();
                        if ($this->check(Token::COMMA)) {
                            $this->pos++;
                        }
                    }
                    $this->expect(Token::RPAREN);
                    $expr = new MethodCallNode($expr, $name, $args, $expr->line);
                } else {
                    $expr = new FieldAccessNode($expr, $name, $expr->line);
                }
            }

            while ($this->check(Token::LBRACKET)) {
                $idx_line = $this->current()->line;
                $this->pos++;
                $idx = $this->parseExpr();
                $this->expect(Token::RBRACKET);
                $expr = new IndexNode($expr, $idx, $idx_line);
            }

            return $expr;
        }

        if ($token->type === Token::IF) {
            return $this->parseIf();
        }

        if ($token->type === Token::PIPE) {
            return $this->parseClosure();
        }

        if ($token->type === Token::LPAREN) {
            $this->pos++;
            $expr = $this->parseExpr();
            $this->expect(Token::RPAREN);
            while ($this->check(Token::LBRACKET)) {
                $idx_line = $this->current()->line;
                $this->pos++;
                $idx = $this->parseExpr();
                $this->expect(Token::RBRACKET);
                $expr = new IndexNode($expr, $idx, $idx_line);
            }
            return $expr;
        }

        throw new RuntimeException(
            "Unexpected token {$token->type}({$token->value}) on line {$token->line}"
        );
    }

    private function parseClosure(): ClosureNode {
        $line = $this->expect(Token::PIPE)->line;
        $params = [];
        while (!$this->check(Token::PIPE) && !$this->check(Token::EOF)) {
            $pname = $this->expect(Token::IDENT)->value;
            $ptype = 'i32';
            if ($this->check(Token::COLON)) {
                $this->pos++;
                $ptype = $this->parseType();
            }
            $params[] = ['name' => $pname, 'type' => $ptype];
            if ($this->check(Token::COMMA)) $this->pos++;
        }
        $this->expect(Token::PIPE);
        if ($this->check(Token::LBRACE)) {
            $body = $this->parseBlock();
        } else {
            $expr = $this->parseExpr();
            $body = [new ReturnNode($expr, $expr->line)];
        }
        return new ClosureNode($params, $body, $line);
    }

    // --- helpers ---

    private function parseTypeParams(): array {
        $params = [];
        $this->last_type_bounds = [];
        if ($this->check(Token::LT)) {
            $this->pos++;
            while (!$this->check(Token::GT)) {
                $name = $this->expect(Token::IDENT)->value;
                $params[] = $name;
                if ($this->check(Token::COLON)) {
                    $this->pos++;
                    $bounds = [];
                    $bounds[] = $this->expect(Token::IDENT)->value;
                    while ($this->check(Token::PLUS)) {
                        $this->pos++;
                        $bounds[] = $this->expect(Token::IDENT)->value;
                    }
                    $this->last_type_bounds[$name] = $bounds;
                }
                if ($this->check(Token::COMMA)) $this->pos++;
            }
            $this->expect(Token::GT);
        }
        return $params;
    }

    private function parseType(): string {
        $ref = '';
        if ($this->check(Token::AMP)) {
            $ref = '&';
            $this->pos++;
            if ($this->check(Token::MUT)) {
                $ref = '&mut ';
                $this->pos++;
            }
        }
        if ($this->check(Token::IDENT) && $this->current()->value === 'str') {
            $this->pos++;
            return $ref . 'str';
        }
        if ($this->check(Token::LBRACKET)) {
            $this->pos++;
            $inner = $this->parseType();
            $this->expect(Token::RBRACKET);
            return $ref . "[$inner]";
        }
        $name = $this->expect(Token::IDENT)->value;
        if ($this->check(Token::LT)) {
            $this->pos++;
            $inner = $this->expect(Token::IDENT)->value;
            $this->expect(Token::GT);
            $name = "$name<$inner>";
        }
        return $ref . $name;
    }

    private function skipAttributes(): void {
        while ($this->check(Token::HASH)) {
            $this->pos++;
            if ($this->check(Token::BANG)) {
                $this->pos++;
            }
            $this->expect(Token::LBRACKET);
            $depth = 1;
            while ($depth > 0 && !$this->check(Token::EOF)) {
                if ($this->check(Token::LBRACKET)) $depth++;
                elseif ($this->check(Token::RBRACKET)) $depth--;
                $this->pos++;
            }
        }
    }

    private function expect(string $type): Token {
        $token = $this->current();
        if ($token->type !== $type) {
            throw new RuntimeException(
                "Expected $type but got {$token->type}({$token->value}) on line {$token->line}"
            );
        }
        $this->pos++;
        return $token;
    }

    private function check(string $type): bool {
        return $this->current()->type === $type;
    }

    private function current(): Token {
        return $this->tokens[$this->pos];
    }
}
