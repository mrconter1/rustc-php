// error: Cannot assign through immutable reference

fn main() {
    let x: i32 = 5;
    let p: &i32 = &x;
    *p = 10;
}
