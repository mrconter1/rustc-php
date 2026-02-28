// exit: 2
fn main() {
    let x: i32 = 10;
    if x == 5 {
        exit(1);
    } else if x == 10 {
        exit(2);
    } else {
        exit(3);
    }
}
