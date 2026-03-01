// exit: 0
// stdout: 5
// stdout: 12
fn pair() -> (i32, i32) {
    return (5, 12);
}
fn main() {
    let (a, b) = pair();
    println!("{}", a);
    println!("{}", b);
}
