// stdout: 1
// stdout: 2
// stdout: 4
// stdout: 8
// stdout: 16
// stdout: 32
// stdout: 1024
// stdout: 243
fn power(base: i32, exp: i32) -> i32 {
    if exp == 0 {
        return 1;
    }
    if exp % 2 == 0 {
        let half: i32 = power(base, exp / 2);
        half * half
    } else {
        base * power(base, exp - 1)
    }
}

fn main() {
    let mut i: i32 = 0;
    while i <= 5 {
        println!("{}", power(2, i));
        i = i + 1;
    }
    println!("{}", power(2, 10));
    println!("{}", power(3, 5));
}
