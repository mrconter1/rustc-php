// stdout: 55

fn main() {
    let mut sum: i32 = 0;
    for i in 1..11 {
        sum = sum + i;
    }
    println!("{}", sum);
}
