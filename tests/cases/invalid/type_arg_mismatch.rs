// error: Type mismatch: argument 1
fn square(x: i32) -> i32 {
    return x * x;
}

fn main() {
    square(true);
}
