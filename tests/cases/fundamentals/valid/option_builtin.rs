// exit: 42
fn main() {
    let x: Option<i32> = Option::<i32>::Some(42);
    let v = match x {
        Option::<i32>::Some(n) => n,
        Option::<i32>::None => 0,
    };
    exit(v);
}
