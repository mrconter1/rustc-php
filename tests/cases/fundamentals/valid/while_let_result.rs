// exit: 0
// stdout: 1
// stdout: 2
fn main() {
    let mut r: Result<i32, i32> = Result::<i32, i32>::Ok(1);
    let mut count = 0;
    while let Result::<i32, i32>::Ok(v) = r {
        println!("{}", v);
        count = count + 1;
        if count >= 2 {
            r = Result::<i32, i32>::Err(0);
        } else {
            r = Result::<i32, i32>::Ok(v + 1);
        }
    }
}
