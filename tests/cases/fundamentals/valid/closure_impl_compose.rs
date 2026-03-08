// exit: 9
fn compose(f: impl Fn(i32) -> i32, g: impl Fn(i32) -> i32, x: i32) -> i32 {
    f(g(x))
}
fn main() {
    exit(compose(|x: i32| x * x, |x: i32| x + 1, 2));
}
