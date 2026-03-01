// exit: 0
// stdout: 10
// stdout: 8
// stdout: 24
// stdout: 8
fn main() {
    let mut x: i32 = 5;
    x += 5;
    println!("{}", x);
    x -= 2;
    println!("{}", x);
    x *= 3;
    println!("{}", x);
    x /= 3;
    println!("{}", x);
}
