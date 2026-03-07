// stdout: 1
// stdout: 5
// stdout: 14
// stdout: 30
// stdout: 55
// exit: 55

fn main() {
    let mut sum: i32 = 0;
    for i in 1..10 {
        if i > 5 {
            break;
        }
        sum = sum + i * i;
        println!("{}", sum);
    }
    exit(sum);
}
