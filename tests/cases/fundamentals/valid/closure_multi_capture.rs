// exit: 28

fn main() {
    let a = 3;
    let b = 4;
    let sum_scaled = |x: i32| (x + a) * b;
    exit(sum_scaled(4));
}
