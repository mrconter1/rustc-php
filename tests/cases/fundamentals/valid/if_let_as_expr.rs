// exit: 0
// stdout: 10
fn main() {
    let opt: Option<i32> = Option::<i32>::Some(10);
    let v = if let Some(n) = opt { n } else { 0 };
    println!("{}", v);
}
