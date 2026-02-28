// stdout: 1
// stdout: 0
fn is_even(n: i32) -> bool {
    return n % 2 == 0;
}

fn main() {
    println!("{}", is_even(4));
    println!("{}", is_even(7));
}
