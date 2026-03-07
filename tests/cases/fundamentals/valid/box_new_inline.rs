// exit: 0

fn main() {
    let x: i32 = *Box::new(42);
    exit(x - 42);
}
