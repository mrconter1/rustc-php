// exit: 0
// stdout: 100
fn main() {
    let r: Result<i32, i32> = Result::<i32, i32>::Ok(100);
    if let Ok(x) = r {
        println!("{}", x);
    } else {
        println!("0");
    }
}
