// exit: 42
fn main() {
    let x: Result<i32, i32> = Result::<i32, i32>::Ok(42);
    let v = match x {
        Result::<i32, i32>::Ok(n) => n,
        Result::<i32, i32>::Err(e) => e,
    };
    exit(v);
}
