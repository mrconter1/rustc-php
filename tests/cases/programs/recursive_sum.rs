// stdout: 15
// stdout: 55
// stdout: 0
// exit: 55

fn sum_to(n: i32) -> i32 {
    if n <= 0 {
        return 0;
    }
    n + sum_to(n - 1)
}

fn main() {
    println!("{}", sum_to(5));
    println!("{}", sum_to(10));
    println!("{}", sum_to(0));
    exit(sum_to(10));
}
