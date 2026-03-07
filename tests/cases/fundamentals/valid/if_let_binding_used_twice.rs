// exit: 0
// stdout: 5
// stdout: 5
fn main() {
    let opt: Option<i32> = Option::<i32>::Some(5);
    if let Some(n) = opt {
        println!("{}", n);
        let _ = n + n;
        println!("{}", n);
    }
}
