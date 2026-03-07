// error: Type mismatch: expected return type

struct S { x: i32 }

fn f() -> i32 {
    S { x: 1 }
}

fn main() {
    f();
}
