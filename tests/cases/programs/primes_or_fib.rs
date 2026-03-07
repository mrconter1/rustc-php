// stdout: fib
// stdout: 0
// stdout: 1
// stdout: 1
// stdout: 2
// stdout: 3
// stdout: primes
// stdout: 2
// stdout: 3
// stdout: 5
// stdout: 7
// stdout: done
// exit: 0

enum Mode {
    Fib,
    Primes,
    Done,
}

fn fib(n: i32) -> i32 {
    if n <= 1 {
        return n;
    }
    let mut a: i32 = 0;
    let mut b: i32 = 1;
    let mut i: i32 = 2;
    while i <= n {
        let t: i32 = b;
        b = a + b;
        a = t;
        i = i + 1;
    }
    b
}

fn is_prime(n: i32) -> bool {
    if n < 2 {
        return false;
    }
    let mut i: i32 = 2;
    while i * i <= n {
        if n % i == 0 {
            return false;
        }
        i = i + 1;
    }
    true
}

fn main() {
    let mut mode = Mode::Fib;
    let mut step: i32 = 0;
    loop {
        match mode {
            Mode::Fib => {
                println!("fib");
                let mut k: i32 = 0;
                while k < 5 {
                    println!("{}", fib(k));
                    k = k + 1;
                }
                mode = Mode::Primes;
            }
            Mode::Primes => {
                println!("primes");
                let mut n: i32 = 2;
                let mut count: i32 = 0;
                while count < 4 {
                    if is_prime(n) {
                        println!("{}", n);
                        count = count + 1;
                    }
                    n = n + 1;
                }
                mode = Mode::Done;
            }
            Mode::Done => {
                println!("done");
                break;
            }
        }
        step = step + 1;
        if step >= 3 {
            break;
        }
    }
    exit(0);
}
