// exit: 0
// stdout: none
fn main() {
    let opt: Option<i32> = Option::<i32>::None;
    if let Option::<i32>::Some(_n) = opt {
        println!("some");
    } else {
        println!("none");
    }
}
