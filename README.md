# rustc-php: A Rust compiler written in PHP

A Rust compiler written in PHP that emits x86-64 Linux binaries directly, no LLVM, no assembler, no linker. Features ownership checking, borrow checking, type checking, structs, functions, control flow, and move semantics. Useful if you need to compile Rust on a shared hosting server from 2008 where the only installed runtime is PHP.

## Installation

In order to execute Rust code you of course first need to install PHP. You can do this easily on Windows 11 by running this command:

```
winget install PHP.PHP.8.4
```

This compiler outputs valid machine code for Linux, so the most practical approach if you're on Windows is to use WSL. Start by installing Ubuntu (if you haven't already):

```
wsl --install
```

After the install completes, reboot your machine and then open Ubuntu from the Start menu to finish the initial setup.

## Usage

Compile a `.rs` file by running:

```
php rustc.php main.rs -o main
```

Then execute the compiled binary through WSL:

```
wsl ./main
```

To see the exit code of the program:

```
wsl ./main; echo $?
```

## Tests

Run the full test suite:

```
php tests/run.php
```

Test cases live in `tests/cases/` organized into `fundamentals/valid/`, `fundamentals/invalid/`, and `programs/`. Each `.rs` file contains expected output in comments at the top (`// exit:`, `// stdout:`, `// error:`).

## Contributing

The compiler currently supports a meaningful subset of Rust. The following features are not yet implemented, roughly in order of impact:

1. `impl` blocks and method calls (`point.distance()`)
2. Enums and `match` expressions
3. Generics (`fn swap<T>(a: &mut T, b: &mut T)`)
4. Traits (`impl Display for Point`)
5. Closures (`|x| x + 1`)
6. `for` loops and iterators
7. The `?` operator
8. Arrays and slices
9. Compound assignment (`+=`, `-=`, `*=`)
10. Tuples
11. `&str` string slices
12. Additional integer types (`u8`, `u32`, `u64`, `i64`, `usize`)
13. `const` and `static` items
14. `f32`/`f64` floating point
