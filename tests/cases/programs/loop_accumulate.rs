// stdout: 1
// stdout: 3
// stdout: 6
// stdout: 10
// stdout: 15
// exit: 15

fn main() {
    let mut sum: i32 = 0;
    let mut n: i32 = 1;
    loop {
        sum = sum + n;
        println!("{}", sum);
        if n >= 5 {
            break;
        }
        n = n + 1;
    }
    exit(sum);
}
