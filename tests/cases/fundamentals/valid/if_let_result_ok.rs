// exit: 0
// stdout: 42
fn main() {
    let r: Result<i32, i32> = Result::<i32, i32>::Ok(42);
    if let Result::<i32, i32>::Ok(v) = r {
        println!("{}", v);
    } else {
        println!("err");
    }
}
