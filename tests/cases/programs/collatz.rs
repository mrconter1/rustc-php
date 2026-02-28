// stdout: 0
// stdout: 1
// stdout: 7
// stdout: 2
// stdout: 5
// stdout: 8
// stdout: 16
// stdout: 3
// stdout: 19
// stdout: 6
fn collatz_steps(n: i32) -> i32 {
    let mut x: i32 = n;
    let mut steps: i32 = 0;
    while x != 1 {
        if x % 2 == 0 {
            x = x / 2;
        } else {
            x = x * 3 + 1;
        }
        steps = steps + 1;
    }
    steps
}

fn main() {
    let mut i: i32 = 1;
    while i <= 10 {
        println!("{}", collatz_steps(i));
        i = i + 1;
    }
}
