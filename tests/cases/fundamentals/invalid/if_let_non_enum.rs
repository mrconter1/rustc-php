// error: Cannot match on non-enum
fn main() {
    let x: i32 = 5;
    if let Some(n) = x {
        println!("{}", n);
    }
}
