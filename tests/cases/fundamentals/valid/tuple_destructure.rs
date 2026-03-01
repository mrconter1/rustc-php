// exit: 0
// stdout: 3
// stdout: 7
fn main() {
    let (a, b) = (1, 2);
    println!("{}", a + b);
    let (x, y): (i32, i32) = (3, 4);
    println!("{}", x + y);
}
