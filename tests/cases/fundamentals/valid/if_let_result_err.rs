// exit: 0
// stdout: 99
fn main() {
    let r: Result<i32, i32> = Result::<i32, i32>::Err(99);
    if let Result::<i32, i32>::Err(e) = r {
        println!("{}", e);
    } else {
        println!("ok");
    }
}
