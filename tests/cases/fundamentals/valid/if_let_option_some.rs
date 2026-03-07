// exit: 0
// stdout: 42
fn main() {
    let opt: Option<i32> = Option::<i32>::Some(42);
    if let Option::<i32>::Some(n) = opt {
        println!("{}", n);
    } else {
        println!("none");
    }
}
