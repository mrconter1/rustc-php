// stdout: 1
// stdout: 4
// stdout: 9
// stdout: 16
// stdout: 25
// stdout: 36
// stdout: 91
// exit: 91

fn sq(n: i32) -> i32 {
    n * n
}

fn main() {
    let mut sum: i32 = 0;
    for i in 1..7 {
        let s = sq(i);
        println!("{}", s);
        sum = sum + s;
    }
    println!("{}", sum);
    exit(sum);
}
