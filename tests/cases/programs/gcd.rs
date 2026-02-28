// stdout: 6
// stdout: 1
// stdout: 12
fn gcd(a: i32, b: i32) -> i32 {
    let mut x: i32 = a;
    let mut y: i32 = b;
    while y != 0 {
        let temp: i32 = y;
        y = x % y;
        x = temp;
    }
    x
}

fn main() {
    println!("{}", gcd(54, 24));
    println!("{}", gcd(17, 13));
    println!("{}", gcd(36, 60));
}
