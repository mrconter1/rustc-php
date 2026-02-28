// stdout: medium
fn main() {
    let n: i32 = 50;
    let size = if n < 10 {
        String::from("small")
    } else if n < 100 {
        String::from("medium")
    } else {
        String::from("large")
    };
    println!("{}", size);
}
