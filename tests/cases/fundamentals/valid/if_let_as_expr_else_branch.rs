// exit: 0
// stdout: 0
fn main() {
    let opt: Option<i32> = Option::<i32>::None;
    let v = if let Some(n) = opt { n } else { 0 };
    println!("{}", v);
}
