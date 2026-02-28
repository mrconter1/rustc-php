// stdout: 120
fn main() {
    let mut n: i32 = 5;
    let mut result: i32 = 1;
    while n > 0 {
        result = result * n;
        n = n - 1;
    }
    println!("{}", result);
}
