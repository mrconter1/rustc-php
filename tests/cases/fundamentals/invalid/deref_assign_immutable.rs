// error: Cannot assign through immutable reference
fn main() {
    let mut n: i32 = 5;
    let r: &i32 = &n;
    *r = 10;
}
