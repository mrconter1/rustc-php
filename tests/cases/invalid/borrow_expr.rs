// error: Type mismatch
fn main() {
    let x: i32 = 5;
    let y: i32 = &(x + 1);
}
