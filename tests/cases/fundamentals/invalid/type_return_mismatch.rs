// error: Type mismatch: expected return type 'i32'
fn bad() -> i32 {
    return true;
}

fn main() {
    bad();
}
