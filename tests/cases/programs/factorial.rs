// stdout: 1
// stdout: 1
// stdout: 2
// stdout: 6
// stdout: 24
// stdout: 120
// exit: 120

fn fact(n: i32) -> i32 {
    if n <= 1 {
        return 1;
    }
    n * fact(n - 1)
}

fn main() {
    let mut i: i32 = 0;
    while i <= 5 {
        println!("{}", fact(i));
        i = i + 1;
    }
    exit(fact(5));
}
