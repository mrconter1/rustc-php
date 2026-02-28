// stdout: === Number Cruncher ===
// stdout: Primes up to 30: 10
// stdout: Twin prime pairs: 4
// stdout: fib(0) = 0
// stdout: fib(1) = 1
// stdout: fib(2) = 1
// stdout: fib(3) = 2
// stdout: fib(4) = 3
// stdout: fib(5) = 5
// stdout: fib(6) = 8
// stdout: fib(7) = 13
// stdout: fib(8) = 21
// stdout: fib(9) = 34
// stdout: gcd(48, 18) = 6
// stdout: gcd(100, 75) = 25
// stdout: Sum of multiples of 3 or 5 up to 100: 2418
// stdout: 17 is prime
// stdout: 42 is not prime
// stdout: Goodbye, World!

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

fn fibonacci(n: i32) -> i32 {
    if n <= 1 {
        return n;
    }
    let mut a: i32 = 0;
    let mut b: i32 = 1;
    let mut i: i32 = 2;
    while i <= n {
        let temp: i32 = b;
        b = a + b;
        a = temp;
        i = i + 1;
    }
    b
}

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

fn describe(n: i32) {
    if is_prime(n) {
        println!("{} is prime", n);
    } else {
        println!("{} is not prime", n);
    }
}

fn farewell(name: &String) {
    println!("Goodbye, {}!", name);
}

fn main() {
    let title = String::from("=== Number Cruncher ===");
    println!("{}", title);

    // Count primes and twin primes up to 30
    let mut count: i32 = 0;
    let mut twins: i32 = 0;
    let mut prev: i32 = 0;
    let mut n: i32 = 2;
    while n <= 30 {
        if is_prime(n) {
            count = count + 1;
            if prev > 0 && n - prev == 2 {
                twins = twins + 1;
            }
            prev = n;
        }
        n = n + 1;
    }
    println!("Primes up to 30: {}", count);
    println!("Twin prime pairs: {}", twins);

    // Fibonacci sequence
    let mut i: i32 = 0;
    while i < 10 {
        println!("fib({}) = {}", i, fibonacci(i));
        i = i + 1;
    }

    // GCD
    println!("gcd(48, 18) = {}", gcd(48, 18));
    println!("gcd(100, 75) = {}", gcd(100, 75));

    // Sum multiples of 3 or 5 using loop/break/continue
    let mut sum: i32 = 0;
    let mut j: i32 = 1;
    loop {
        if j > 100 {
            break;
        }
        if !(j % 3 == 0 || j % 5 == 0) {
            j = j + 1;
            continue;
        }
        sum = sum + j;
        j = j + 1;
    }
    println!("Sum of multiples of 3 or 5 up to 100: {}", sum);

    // Describe numbers
    describe(17);
    describe(42);

    // Borrow and farewell
    let world = String::from("World");
    farewell(&world);
}
