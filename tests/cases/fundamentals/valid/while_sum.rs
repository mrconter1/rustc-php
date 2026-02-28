// stdout: 55
fn main() {
    let mut sum: i32 = 0;
    let mut i: i32 = 1;
    while i <= 10 {
        sum = sum + i;
        i = i + 1;
    }
    println!("{}", sum);
}
