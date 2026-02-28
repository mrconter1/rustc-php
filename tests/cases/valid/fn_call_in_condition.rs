// stdout: even
fn is_even(n: i32) -> bool {
    return n % 2 == 0;
}

fn main() {
    if is_even(4) {
        println!("even");
    } else {
        println!("odd");
    }
}
