// stdout: 0
// stdout: 1
// stdout: 1
// stdout: 0
// stdout: 1
// stdout: 0
// stdout: 1
// stdout: 0
// stdout: 0
// stdout: 0
fn is_prime(n: i32) -> i32 {
    if n < 2 {
        return 0;
    }
    let mut i: i32 = 2;
    while i * i <= n {
        if n % i == 0 {
            return 0;
        }
        i = i + 1;
    }
    1
}

fn main() {
    let mut n: i32 = 1;
    while n <= 10 {
        println!("{}", is_prime(n));
        n = n + 1;
    }
}
