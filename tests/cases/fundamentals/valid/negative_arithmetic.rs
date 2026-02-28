// stdout: 3
fn abs(x: i32) -> i32 {
    if x < 0 {
        return -x;
    }
    return x;
}

fn main() {
    println!("{}", abs(-3));
}
