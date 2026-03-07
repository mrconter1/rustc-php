// exit: 0
// stdout: 7
fn main() {
    let opt: Option<i32> = Option::<i32>::Some(7);
    if let Some(x) = opt {
        println!("{}", x);
    } else {
        println!("0");
    }
}
