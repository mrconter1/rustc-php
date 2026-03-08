// exit: 15
fn apply(f: impl Fn(i32) -> i32, x: i32) -> i32 {
    f(x)
}
fn main() {
    let offset = 10;
    exit(apply(|x: i32| x + offset, 5));
}
