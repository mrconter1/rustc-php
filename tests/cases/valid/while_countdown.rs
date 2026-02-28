// stdout: 3
// stdout: 2
// stdout: 1
fn main() {
    let mut x: i32 = 3;
    while x > 0 {
        println!("{}", x);
        x = x - 1;
    }
}
