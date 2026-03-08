// exit: 42
fn run(f: impl Fn() -> i32) -> i32 {
    f()
}
fn main() {
    let val = 42;
    exit(run(|| val));
}
