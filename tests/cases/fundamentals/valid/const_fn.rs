// exit: 0
// stdout: 3
const fn two() -> i32 {
    2
}

fn main() {
    let x: i32 = two() + 1;
    println!("{}", x);
}
